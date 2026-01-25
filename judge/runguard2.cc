/*
   runguard2 -- unified process isolation and interactive problem support.

   Part of the DOMjudge Programming Contest Jury System and licensed
   under the GNU GPL. See README and COPYING for details.

   This is a merger of runguard.cc and runpipe.cc to:
   - Eliminate double data-pumping overhead for interactive problems
   - Run both program and validator with restrictions
   - Properly terminate both children on timeout
   - Share code instead of duplicating pipe pumping logic

   Program specifications:

   Non-interactive mode (backward compatible with runguard):
     runguard2 [options] -- PROGRAM [ARGS...]

   Interactive mode:
     runguard2 [options] --interactive \
         --validator-cmd="VALIDATOR [ARGS...]" \
         --validator-meta=FILE \
         --interaction-log=FILE \
         -- PROGRAM [ARGS...]

   Both modes apply the same restrictions: cgroups, rlimits, chroot, user switching.
   In interactive mode, both program and validator run with restrictions in the
   same cgroup for combined resource tracking.
*/

#include "config.h"

#include "lib.error.hpp"
#include "lib.misc.h"
#include "runguard_common.h"

/* Some system/site specific config: VALID_USERS, CHROOT_PREFIX */
#include "runguard-config.h"

#include <algorithm>
#include <array>
#include <chrono>
#include <iostream>
#include <fstream>
#include <sstream>
#include <string>
#include <vector>
#include <set>
#include <utility>
#include <format>

#include <sys/types.h>
#include <sys/wait.h>
#include <sys/param.h>
#include <sys/stat.h>
#include <sys/time.h>
#include <sys/times.h>
#include <sys/resource.h>
#include <sys/epoll.h>
#include <sys/sysinfo.h>
#include <cerrno>
#include <fcntl.h>
#include <csignal>
#include <cstdlib>
#include <mntent.h>
#include <unistd.h>
#include <cstring>
#include <cstdarg>
#include <cstdio>
#include <getopt.h>
#include <fnmatch.h>
#include <regex.h>
#include <pwd.h>
#include <grp.h>
#include <ctime>
#include <cmath>
#include <climits>
#include <cinttypes>
#include <libcgroup.h>
#include <sched.h>

#define PROGRAM "runguard2"
#define VERSION DOMJUDGE_VERSION "/" REVISION

#define BUF_SIZE (4*1024)
#define EPOLL_MAX_EVENTS 16

/* Types of time for writing to file. */
#define WALL_TIME_TYPE 0
#define CPU_TIME_TYPE  1

/* Strings to write to file when exceeding no/soft/hard/both limits. */
const char output_timelimit_str[4][16] = {
    "",
    "soft-timelimit",
    "hard-timelimit",
    "hard-timelimit"
};
/* Bitmask of soft/hard timelimit */
const int soft_timelimit = 1;
const int hard_timelimit = 2;

const struct timespec killdelay = { 0, 100000000L }; /* 0.1 seconds */
const struct timespec cg_delete_delay = { 0, 10000000L }; /* 0.01 seconds */

extern int errno;
extern int verbose;

std::string_view progname;
char  *cmdname;
char **cmdargs;
char  *rootdir;
char  *rootchdir;
char  *stdoutfilename;
char  *stderrfilename;
char  *metafilename;
std::vector<std::string> environment_variables;
FILE  *metafile;

char  cgroupname[255];
const char *cpuset;

char *runuser;
char *rungroup;
int runuid;
int rungid;
int use_root;
int use_walltime;
int use_cputime;
int use_user;
int use_group;
int redir_stdout;
int redir_stderr;
int limit_streamsize;
int outputmeta;
int outputtimetype;
int no_coredump;
int preserve_environment;
int show_help;
int show_version;
int in_error_handling = 0;

/* Interactive mode options */
int interactive_mode = 0;
char *validator_cmd = nullptr;
char *validator_metafilename = nullptr;
char *interaction_logfilename = nullptr;
FILE *validator_metafile = nullptr;
int interaction_logfd = -1;

double walltimelimit[2], cputimelimit[2]; /* in seconds, soft and hard limits */
int walllimit_reached, cpulimit_reached; /* 1=soft, 2=hard, 3=both limits reached */
rlim_t memsize;
rlim_t filesize;
rlim_t nproc;
size_t streamsize;

pid_t child_pid = -1;
pid_t validator_pid = -1;

/* Signal handling via epoll */
int signal_pipe[2] = {-1, -1};
int epoll_fd = -1;
static volatile sig_atomic_t received_signal = -1;

/* Pipe file descriptors for child processes */
int child_pipefd[3][2];
int child_redirfd[3];

/* Interactive mode pipes:
   program stdout -> validator stdin (pipe A)
   validator stdout -> program stdin (pipe B)
   program stderr -> file (pipe C)
*/
int pipe_prog_to_val[2] = {-1, -1};  /* pipe A */
int pipe_val_to_prog[2] = {-1, -1};  /* pipe B */
int pipe_prog_stderr[2] = {-1, -1};  /* pipe C */
int pipe_val_stderr[2] = {-1, -1};   /* validator stderr */

struct timeval progstarttime, starttime, endtime;
struct tms startticks, endticks;

/* Process state tracking for interactive mode */
struct ChildState {
    pid_t pid = -1;
    bool exited = false;
    int exit_status = 0;
    bool is_program = true;
    size_t bytes_written = 0;
    size_t bytes_read = 0;
};

/* Ring buffer for interaction logging - avoids blocking on disk I/O */
struct InteractionLog {
    static constexpr size_t MAX_BUFFER_SIZE = 16 * 1024 * 1024; /* 16 MB max */
    static constexpr size_t FLUSH_THRESHOLD = 8 * 1024 * 1024;  /* Flush at 8 MB */

    struct Entry {
        int time_ms;
        bool from_program;  /* true = program->validator, false = validator->program */
        bool is_eof;
        std::vector<char> data;
    };

    std::vector<Entry> entries;
    std::chrono::time_point<std::chrono::steady_clock> start;
    size_t total_size = 0;
    int fd = -1;
    bool overflow = false;

    void init(int log_fd) {
        fd = log_fd;
        start = std::chrono::steady_clock::now();
        entries.reserve(1024);
    }

    void add_entry(const char* data, size_t len, bool from_program) {
        if (fd < 0) return;

        auto duration = std::chrono::steady_clock::now() - start;
        int time_ms = duration.count() / 1000000; /* ns -> ms */

        /* If we're over the max size, just count overflow */
        if (total_size + len > MAX_BUFFER_SIZE) {
            overflow = true;
            return;
        }

        Entry e;
        e.time_ms = time_ms;
        e.from_program = from_program;
        e.is_eof = false;
        e.data.assign(data, data + len);
        entries.push_back(std::move(e));
        total_size += len;

        /* Flush if we hit the threshold */
        if (total_size >= FLUSH_THRESHOLD) {
            flush();
        }
    }

    void add_eof(bool from_program) {
        if (fd < 0) return;

        auto duration = std::chrono::steady_clock::now() - start;
        int time_ms = duration.count() / 1000000;

        Entry e;
        e.time_ms = time_ms;
        e.from_program = from_program;
        e.is_eof = true;
        entries.push_back(std::move(e));
    }

    void flush() {
        if (fd < 0 || entries.empty()) return;

        /* Build output in memory first, then write once */
        std::string output;
        output.reserve(total_size + entries.size() * 32); /* data + headers */

        for (const auto& e : entries) {
            int time_sec = e.time_ms / 1000;
            int time_millis = e.time_ms % 1000;

            char header[64];
            if (e.is_eof) {
                char direction = e.from_program ? ']' : '[';
                snprintf(header, sizeof(header), "[%3d.%03ds/0]%c",
                         time_sec, time_millis, direction);
                output += header;
            } else {
                char direction = e.from_program ? '>' : '<';
                snprintf(header, sizeof(header), "[%3d.%03ds/%zu]%c: ",
                         time_sec, time_millis, e.data.size(), direction);
                output += header;
                output.append(e.data.begin(), e.data.end());
                output += '\n';
            }
        }

        if (overflow) {
            output += "[OVERFLOW: some data was not logged due to buffer size limit]\n";
        }

        write_all(fd, output.data(), output.size());
        entries.clear();
        total_size = 0;
    }
};

ChildState program_state;
ChildState validator_state;

struct option const long_opts[] = {
    {"root",              required_argument, nullptr,         'r'},
    {"user",              required_argument, nullptr,         'u'},
    {"group",             required_argument, nullptr,         'g'},
    {"chdir",             required_argument, nullptr,         'd'},
    {"walltime",          required_argument, nullptr,         't'},
    {"cputime",           required_argument, nullptr,         'C'},
    {"memsize",           required_argument, nullptr,         'm'},
    {"filesize",          required_argument, nullptr,         'f'},
    {"nproc",             required_argument, nullptr,         'p'},
    {"cpuset",            required_argument, nullptr,         'P'},
    {"no-core",           no_argument,       nullptr,         'c'},
    {"stdout",            required_argument, nullptr,         'o'},
    {"stderr",            required_argument, nullptr,         'e'},
    {"streamsize",        required_argument, nullptr,         's'},
    {"environment",       no_argument,       nullptr,         'E'},
    {"variable",          required_argument, nullptr,         'V'},
    {"outmeta",           required_argument, nullptr,         'M'},
    {"verbose",           no_argument,       nullptr,         'v'},
    {"quiet",             no_argument,       nullptr,         'q'},
    /* Interactive mode options */
    {"interactive",       no_argument,       nullptr,         'I'},
    {"validator-cmd",     required_argument, nullptr,         'X'},
    {"validator-meta",    required_argument, nullptr,         'Y'},
    {"interaction-log",   required_argument, nullptr,         'L'},
    {"help",              no_argument,       &show_help,       1 },
    {"version",           no_argument,       &show_version,    1 },
    { nullptr,            0,                 nullptr,          0 }
};

/* Forward declarations */
template<typename... Args>
void write_meta(const std::string& key, std::format_string<Args...> fmt, Args&&... args);
template<typename... Args>
void write_validator_meta(const std::string& key, std::format_string<Args...> fmt, Args&&... args);
template<typename... Args>
[[noreturn]] void die(int errnum, std::format_string<Args...> fmt, Args&&... args);
void cgroup_create();
void cgroup_kill();
void cgroup_delete();
void output_cgroup_stats(double *cputime);
int run_non_interactive();
int run_interactive();

/* =========== Signal Handlers =========== */

void signal_handler(int sig)
{
    /* Write signal to pipe for epoll to pick up */
    char c = (char)sig;
    [[maybe_unused]] auto r = write(signal_pipe[PIPE_IN], &c, 1);
    received_signal = sig;
}

void install_signal_handlers()
{
    /* Create signal pipe for epoll integration */
    if (pipe2(signal_pipe, O_CLOEXEC | O_NONBLOCK) != 0) {
        die(errno, "creating signal pipe");
    }

    struct sigaction sigact;
    sigset_t emptymask;
    if (sigemptyset(&emptymask) != 0) die(errno, "creating empty signal mask");

    sigact.sa_handler = signal_handler;
    sigact.sa_flags = SA_RESTART;
    sigact.sa_mask = emptymask;

    if (sigaction(SIGCHLD, &sigact, nullptr) != 0) {
        die(errno, "installing SIGCHLD handler");
    }
    if (sigaction(SIGALRM, &sigact, nullptr) != 0) {
        die(errno, "installing SIGALRM handler");
    }
    if (sigaction(SIGTERM, &sigact, nullptr) != 0) {
        die(errno, "installing SIGTERM handler");
    }

    /* Ignore SIGPIPE - we handle broken pipes via read/write errors */
    signal(SIGPIPE, SIG_IGN);
}

/* =========== Error Handling =========== */

template<typename... Args>
[[noreturn]] void die(int errnum, std::format_string<Args...> fmt, Args&&... args)
{
    /* Silently ignore errors that happen while handling other errors. */
    if (in_error_handling) std::exit(exit_failure);
    in_error_handling = 1;

    /*
     * Make sure the signal handler for these does not interfere,
     * we are exiting now anyway.
     */
    sigset_t sigs;
    sigemptyset(&sigs);
    sigaddset(&sigs, SIGALRM);
    sigaddset(&sigs, SIGTERM);
    sigprocmask(SIG_BLOCK, &sigs, nullptr);

    std::string errstr(progname);
    errstr += ": ";
    try {
        errstr += std::format(fmt, std::forward<Args>(args)...);
    } catch (const std::exception& e) {
        errstr += "Error formatting error message: " + std::string(e.what());
    }

    if (errnum == 0) {
        errstr += ": unknown error";
    } else {
        /* Special case libcgroup error codes. */
        if (errnum == ECGOTHER) {
            errstr += ": libcgroup";
            errnum = errno;
        }
        if (errnum >= ECGROUPNOTCOMPILED && errnum <= ECGROUPNOTCOMPILED) {
            errstr += ": ";
            errstr += cgroup_strerror(errnum);
        } else {
            errstr += ": ";
            errstr += strerror(errnum);
        }
    }

    std::cerr << errstr << std::endl;

    /* Write default timing values so metadata parsing doesn't fail */
    write_meta("exitcode", "{}", 1);
    write_meta("wall-time", "{:.3f}", 0.0);
    write_meta("user-time", "{:.3f}", 0.0);
    write_meta("sys-time", "{:.3f}", 0.0);
    write_meta("cpu-time", "{:.3f}", 0.0);
    write_meta("time-used", "cpu-time");
    write_meta("memory-bytes", "{}", 0);
    write_meta("internal-error", "{}", errstr);
    if (outputmeta && metafile != nullptr && fclose(metafile) != 0) {
        fprintf(stderr, "\nError writing to metafile '%s'.\n", metafilename);
    }

    /* Make sure that all children are killed before terminating */
    if (child_pid > 0) {
        logmsg(LOG_DEBUG, "sending SIGKILL to program");
        if (kill(-child_pid, SIGKILL) != 0 && errno != ESRCH) {
            logmsg(LOG_ERR, "unable to send SIGKILL to program: {}", strerror(errno));
        }
    }
    if (validator_pid > 0) {
        logmsg(LOG_DEBUG, "sending SIGKILL to validator");
        if (kill(-validator_pid, SIGKILL) != 0 && errno != ESRCH) {
            logmsg(LOG_ERR, "unable to send SIGKILL to validator: {}", strerror(errno));
        }
    }

    /* Wait a while to make sure the process is killed by now. */
    nanosleep(&killdelay, nullptr);

    std::exit(exit_failure);
}

/* =========== Metadata Output =========== */

template<typename... Args>
void write_meta(const std::string& key, std::format_string<Args...> fmt, Args&&... args)
{
    if (!outputmeta) return;

    if (fprintf(metafile, "%s: ", key.c_str()) <= 0) {
        outputmeta = 0;
        die(0, "cannot write to file `{}'", metafilename);
    }

    try {
        std::string value = std::format(fmt, std::forward<Args>(args)...);
        if (fprintf(metafile, "%s\n", value.c_str()) <= 0) {
            outputmeta = 0;
            die(0, "cannot write to file `{}'", metafilename);
        }
    } catch (const std::exception& e) {
        outputmeta = 0;
        die(0, "Error formatting meta value for key {}: {}", key, e.what());
    }
}

template<typename... Args>
void write_validator_meta(const std::string& key, std::format_string<Args...> fmt, Args&&... args)
{
    if (validator_metafile == nullptr) return;

    if (fprintf(validator_metafile, "%s: ", key.c_str()) <= 0) {
        die(0, "cannot write to validator meta file `{}'", validator_metafilename);
    }

    try {
        std::string value = std::format(fmt, std::forward<Args>(args)...);
        if (fprintf(validator_metafile, "%s\n", value.c_str()) <= 0) {
            die(0, "cannot write to validator meta file `{}'", validator_metafilename);
        }
    } catch (const std::exception& e) {
        die(0, "Error formatting validator meta value for key {}: {}", key, e.what());
    }
}

/* =========== Usage and Argument Parsing =========== */

void usage()
{
    printf("\
Usage: %s [OPTION]... COMMAND...\n\
Run COMMAND with restrictions.\n\
\n", progname.data());
    printf("\
  -r, --root=ROOT           run COMMAND with root directory set to ROOT\n\
  -u, --user=USER           run COMMAND as user with username or ID USER\n\
  -g, --group=GROUP         run COMMAND under group with name or ID GROUP\n\
  -d, --chdir=DIR           change to directory DIR after setting root directory\n\
  -t, --walltime=TIME       kill COMMAND after TIME wallclock seconds\n\
  -C, --cputime=TIME        set maximum CPU time to TIME seconds\n\
  -m, --memsize=SIZE        set total memory limit to SIZE kB\n\
  -f, --filesize=SIZE       set maximum created filesize to SIZE kB;\n");
    printf("\
  -p, --nproc=N             set maximum no. processes to N\n\
  -P, --cpuset=ID           use only processor number ID (or set, e.g. \"0,2-3\")\n\
  -c, --no-core             disable core dumps\n\
  -o, --stdout=FILE         redirect COMMAND stdout output to FILE\n\
  -e, --stderr=FILE         redirect COMMAND stderr output to FILE\n\
  -s, --streamsize=SIZE     truncate COMMAND stdout/stderr streams at SIZE kB\n\
  -E, --environment         preserve environment variables (default only PATH)\n\
  -V, --variable            add additional environment variables\n\
                              (in form KEY=VALUE;KEY2=VALUE2); may be passed\n\
                              multiple times\n\
  -M, --outmeta=FILE        write metadata (runtime, exitcode, etc.) to FILE\n");
    printf("\
\nInteractive mode options:\n\
  -I, --interactive         enable interactive mode\n\
      --validator-cmd=CMD   validator command (required for interactive mode)\n\
      --validator-meta=FILE write validator metadata to FILE\n\
      --interaction-log=FILE write interaction log to FILE\n");
    printf("\
\nOther options:\n\
  -v, --verbose             display some extra warnings and information\n\
  -q, --quiet               suppress all warnings and verbose output\n\
      --help                display this help and exit\n\
      --version             output version information and exit\n");
    printf("\n\
Note that root privileges are needed for the `root' and `user' options.\n\
If `user' is set, then `group' defaults to the same to prevent security\n\
issues, since otherwise the process would retain group root permissions.\n\
The COMMAND path is relative to the changed ROOT directory if specified.\n\
TIME may be specified as a float; two floats separated by `:' are treated\n\
as soft and hard limits. The runtime written to file is that of the last\n\
of wall/cpu time options set, and defaults to CPU time when neither is set.\n");
    std::exit(0);
}

int userid(char *name)
{
    errno = 0;
    struct passwd *pwd = getpwnam(name);
    if (pwd == nullptr || errno) return -1;
    return (int)pwd->pw_uid;
}

int groupid(char *name)
{
    errno = 0;
    struct group *grp = getgrnam(name);
    if (grp == nullptr || errno) return -1;
    return (int)grp->gr_gid;
}

char *username()
{
    int saved_errno = errno;
    errno = 0;
    struct passwd *pwd = getpwuid(getuid());
    if (pwd == nullptr || errno) die(errno, "failed to get username");
    errno = saved_errno;
    return pwd->pw_name;
}

long read_optarg_int(const char *desc, long minval, long maxval)
{
    char *ptr;
    errno = 0;
    long arg = strtol(optarg, &ptr, 10);
    if (errno || *ptr != '\0' || arg < minval || arg > maxval) {
        die(errno, "invalid {} specified: `{}'", desc, optarg);
    }
    return arg;
}

void read_optarg_time(const char *desc, double *times)
{
    char *optcopy;
    if ((optcopy = strdup(optarg)) == nullptr) die(0, "strdup() failed");

    /* Check for soft:hard limit separator and cut string. */
    char *sep;
    if ((sep = strchr(optcopy, ':')) != nullptr) *sep = 0;

    char *ptr;
    errno = 0;
    times[0] = strtod(optcopy, &ptr);
    if (errno || *ptr != '\0' || !std::isfinite(times[0]) || times[0] <= 0) {
        die(errno, "invalid {} specified: `{}'", desc, optarg);
    }

    /* And repeat for hard limit if we found the ':' separator. */
    if (sep != nullptr) {
        errno = 0;
        times[1] = strtod(sep + 1, &ptr);
        if (errno || *(sep + 1) == '\0' || *ptr != '\0' || !std::isfinite(times[1]) || times[1] <= 0) {
            die(errno, "invalid {} specified: `{}'", desc, optarg);
        }
        if (times[1] < times[0]) {
            die(0, "invalid {} specified: hard limit is lower than soft limit", desc);
        }
    } else {
        /* Set soft and hard limits equal. */
        times[1] = times[0];
    }

    free(optcopy);
}

std::set<unsigned> parse_cpuset(std::string cpus)
{
    std::stringstream ss(cpus);
    std::set<unsigned> result;

    std::string token;
    while (getline(ss, token, ',')) {
        size_t split = token.find('-');
        if (split != std::string::npos) {
            std::string token1 = token.substr(0, split);
            std::string token2 = token.substr(split + 1);
            size_t len;
            unsigned cpu1 = std::stoul(token1, &len);
            if (len < token1.length()) die(0, "failed to parse cpuset `{}'", cpus);
            unsigned cpu2 = std::stoul(token2, &len);
            if (len < token2.length()) die(0, "failed to parse cpuset `{}'", cpus);
            for (unsigned i = cpu1; i <= cpu2; i++) result.insert(i);
        } else {
            size_t len;
            unsigned cpu = std::stoul(token, &len);
            if (len < token.length()) die(0, "failed to parse cpuset `{}'", cpus);
            result.insert(cpu);
        }
    }

    return result;
}

std::set<unsigned> read_cpuset(const char *path)
{
    FILE *file = fopen(path, "r");
    if (file == nullptr) die(errno, "opening file `{}'", path);

    char cpuset_buf[1024];
    if (fgets(cpuset_buf, 1024, file) == nullptr) die(errno, "reading from file `{}'", path);

    size_t len = strlen(cpuset_buf);
    if (len > 0 && cpuset_buf[len - 1] == '\n') cpuset_buf[len - 1] = 0;

    if (fclose(file) != 0) die(errno, "closing file `{}'", path);

    return parse_cpuset(cpuset_buf);
}

/* =========== Cgroup Management =========== */

void check_remaining_procs()
{
    char path[1024];
    snprintf(path, 1023, "/sys/fs/cgroup/%s/cgroup.procs", cgroupname);

    FILE *file = fopen(path, "r");
    if (file == nullptr) {
        die(errno, "opening cgroups file `{}'", path);
    }

    fseek(file, 0L, SEEK_END);
    if (ftell(file) > 0) {
        die(0, "found left-over processes in cgroup controller, please check!");
    }
    if (fclose(file) != 0) die(errno, "closing file `{}'", path);
}

void output_cgroup_stats(double *cputime)
{
    struct cgroup *cg;
    if ((cg = cgroup_new_cgroup(cgroupname)) == nullptr) die(0, "cgroup_new_cgroup");

    int ret;
    if ((ret = cgroup_get_cgroup(cg)) != 0) die(ret, "get cgroup information");

    struct cgroup_controller *cg_controller = cgroup_get_controller(cg, "memory");
    int64_t max_usage = 0;
    ret = cgroup_get_value_int64(cg_controller, "memory.peak", &max_usage);
    if (ret == ECGROUPVALUENOTEXIST) {
        die(ret, "kernel too old and does not support memory.peak");
    } else if (ret != 0) {
        die(ret, "get cgroup value memory.peak");
    }

    logmsg(LOG_DEBUG, "total memory used: {} kB", max_usage / 1024);
    write_meta("memory-bytes", "{}", max_usage);

    struct cgroup_stat stat;
    void *handle;
    ret = cgroup_read_stats_begin("cpu", cgroupname, &handle, &stat);
    while (ret == 0) {
        logmsg(LOG_DEBUG, "cpu.stat: {} = {}", stat.name, stat.value);
        if (strcmp(stat.name, "usage_usec") == 0) {
            long long usec = strtoll(stat.value, nullptr, 10);
            *cputime = usec / 1e6;
        }
        ret = cgroup_read_stats_next(&handle, &stat);
    }
    if (ret != ECGEOF) die(ret, "get cgroup value cpu.stat");
    cgroup_read_stats_end(&handle);

    cgroup_free(&cg);
}

#define cgroup_add_value(type, name, value) \
    ret = cgroup_add_value_##type(cg_controller, name, value); \
    if (ret != 0) die(ret, "set cgroup value " #name);

void cgroup_create()
{
    struct cgroup *cg;
    cg = cgroup_new_cgroup(cgroupname);
    if (!cg) die(0, "cgroup_new_cgroup");

    struct cgroup_controller *cg_controller;
    if ((cg_controller = cgroup_add_controller(cg, "memory")) == nullptr) {
        die(0, "cgroup_add_controller memory");
    }

    int ret;
    if (memsize != RLIM_INFINITY) {
        cgroup_add_value(uint64, "memory.max", memsize);
        cgroup_add_value(uint64, "memory.swap.max", 0);
    } else {
        cgroup_add_value(string, "memory.max", "max");
        cgroup_add_value(string, "memory.swap.max", "max");
    }

    if (cpuset != nullptr && strlen(cpuset) > 0) {
        if ((cg_controller = cgroup_add_controller(cg, "cpuset")) == nullptr) {
            die(0, "cgroup_add_controller cpuset");
        }
        cgroup_add_value(string, "cpuset.mems", "0");
        cgroup_add_value(string, "cpuset.cpus", cpuset);
    } else {
        logmsg(LOG_DEBUG, "cpuset undefined");
    }

    if ((ret = cgroup_create_cgroup(cg, 1)) != 0) die(ret, "creating cgroup");

    cgroup_free(&cg);
    logmsg(LOG_DEBUG, "created cgroup `{}'", cgroupname);
}

#undef cgroup_add_value

void cgroup_kill()
{
    char mem_controller[10] = "memory";
    int size;
    do {
        pid_t *pids;
        int ret = cgroup_get_procs(cgroupname, mem_controller, &pids, &size);
        if (ret != 0) die(ret, "cgroup_get_procs");
        for (int i = 0; i < size; i++) {
            kill(pids[i], SIGKILL);
        }
        free(pids);
    } while (size > 0);
}

void cgroup_delete()
{
    struct cgroup *cg;
    cg = cgroup_new_cgroup(cgroupname);
    if (!cg) die(0, "cgroup_new_cgroup");

    if (cgroup_add_controller(cg, "cpu") == nullptr) die(0, "cgroup_add_controller cpu");
    if (cgroup_add_controller(cg, "memory") == nullptr) die(0, "cgroup_add_controller memory");

    if (cpuset != nullptr && strlen(cpuset) > 0) {
        if (cgroup_add_controller(cg, "cpuset") == nullptr) die(0, "cgroup_add_controller cpuset");
    }

    nanosleep(&cg_delete_delay, nullptr);
    int ret = cgroup_delete_cgroup_ext(cg, CGFLAG_DELETE_IGNORE_MIGRATION | CGFLAG_DELETE_RECURSIVE);
    if (ret != 0 && ret != ECGOTHER) die(ret, "deleting cgroup");

    cgroup_free(&cg);
    logmsg(LOG_DEBUG, "deleted cgroup `{}'", cgroupname);
}

/* =========== Child Process Setup =========== */

void setrestrictions()
{
    /* Clear environment to prevent all kinds of security holes, save PATH */
    if (!preserve_environment) {
        char *path;
        path = getenv("PATH");
        environ[0] = nullptr;
        if (path != nullptr) setenv("PATH", path, 1);
    }

    /* Set additional environment variables. */
    for (const auto &tokens : environment_variables) {
        char *token = strtok(strdup(tokens.c_str()), ";");
        while (token != nullptr) {
            logmsg(LOG_DEBUG, "setting environment variable: {}", token);
            putenv(token);
            token = strtok(nullptr, ";");
        }
    }

    struct rlimit lim;
#define setlim(type) \
    if (setrlimit(RLIMIT_##type, &lim) != 0) { \
        if (errno == EPERM) { \
            warning(0, "no permission to set resource RLIMIT_" #type); \
        } else { \
            die(errno, "setting resource RLIMIT_" #type); \
        } \
    }

    if (use_cputime) {
        rlim_t cputime_limit = (rlim_t)ceil(cputimelimit[1]);
        logmsg(LOG_DEBUG, "setting hard CPU-time limit to {}(+1) seconds", (int)cputime_limit);
        lim.rlim_cur = cputime_limit;
        lim.rlim_max = cputime_limit + 1;
        setlim(CPU);
    }

    /* Memory limits should be unlimited, since we use cgroups. */
    lim.rlim_cur = lim.rlim_max = RLIM_INFINITY;
    setlim(AS);
    setlim(DATA);

    /* Always set the stack size to be unlimited. */
    lim.rlim_cur = lim.rlim_max = RLIM_INFINITY;
    setlim(STACK);

    if (filesize != RLIM_INFINITY) {
        logmsg(LOG_DEBUG, "setting filesize limit to {} bytes", filesize);
        lim.rlim_cur = lim.rlim_max = filesize;
        setlim(FSIZE);
    }

    if (nproc != RLIM_INFINITY) {
        logmsg(LOG_DEBUG, "setting process limit to {}", (int)nproc);
        lim.rlim_cur = lim.rlim_max = nproc;
        setlim(NPROC);
    }

#undef setlim

    if (no_coredump) {
        logmsg(LOG_DEBUG, "disabling core dumps");
        lim.rlim_cur = lim.rlim_max = 0;
        if (setrlimit(RLIMIT_CORE, &lim) != 0) die(errno, "disabling core dumps");
    }

    /* Put the child process in the cgroup */
    const char *controllers[] = {"memory", nullptr};
    if (cgroup_change_cgroup_path(cgroupname, getpid(), controllers) != 0) {
        die(0, "Failed to move the process to the cgroup");
    }

    /* Run the command in a separate process group so that the command
       and all its children can be killed off with one signal. */
    if (setsid() == -1) die(errno, "setsid failed");

    /* Set root-directory and change directory to there. */
    if (use_root) {
        if (chdir(rootdir) != 0) die(errno, "cannot chdir to `{}'", rootdir);

        char cwd[PATH_MAX + 1];
        if (getcwd(cwd, PATH_MAX) == nullptr) die(errno, "cannot get directory");
        if (cwd[strlen(cwd) - 1] != '/') strcat(cwd, "/");

        char *path;
        if ((path = (char *)malloc(PATH_MAX + 1)) == nullptr) {
            die(errno, "allocating memory");
        }
        if (realpath(CHROOT_PREFIX, path) == nullptr) {
            die(errno, "cannot canonicalize path `{}'", CHROOT_PREFIX);
        }

        if (strncmp(cwd, path, strlen(path)) != 0) {
            die(0, "invalid root: must be within `{}'", path);
        }
        free(path);

        if (chroot(".") != 0) die(errno, "cannot change root to `{}'", cwd);
        if (chdir("/") != 0) die(errno, "cannot chdir to `/' in chroot");
        if (rootchdir != nullptr) {
            if (chdir(rootchdir) != 0) die(errno, "cannot chdir to `{}' in chroot", rootchdir);
        }
        logmsg(LOG_DEBUG, "using root-directory `{}'", cwd);
    }

    /* Set group-id (must be root for this, so before setting user). */
    if (use_group) {
        if (setgid(rungid)) die(errno, "cannot set group ID to `{}'", rungid);
        if (setgroups(0, nullptr)) die(errno, "cannot clear auxiliary groups");
        logmsg(LOG_DEBUG, "using group ID `{}'", rungid);
    }

    /* Set user-id (must be root for this). */
    if (use_user) {
        if (setuid(runuid)) die(errno, "cannot set user ID to `{}'", runuid);
        logmsg(LOG_DEBUG, "using user ID `{}' for command", runuid);
    } else {
        if (setuid(getuid())) die(errno, "cannot reset real user ID");
        logmsg(LOG_DEBUG, "reset user ID to `{}' for command", getuid());
    }

    if (geteuid() == 0 || getuid() == 0) {
        die(0, "root privileges not dropped. Do not run judgedaemon as root.");
    }
}

/* =========== Time Output =========== */

void output_exit_time(int exitcode, double cpudiff)
{
    logmsg(LOG_DEBUG, "command exited with exitcode {}", exitcode);
    write_meta("exitcode", "{}", exitcode);

    if (received_signal != -1 && received_signal != SIGCHLD) {
        write_meta("signal", "{}", (int)received_signal);
    }

    double walldiff = (endtime.tv_sec - starttime.tv_sec) +
                      (endtime.tv_usec - starttime.tv_usec) * 1E-6;

    unsigned long ticks_per_second = sysconf(_SC_CLK_TCK);
    double userdiff = (double)(endticks.tms_cutime - startticks.tms_cutime) / ticks_per_second;
    double sysdiff = (double)(endticks.tms_cstime - startticks.tms_cstime) / ticks_per_second;

    write_meta("wall-time", "{:.3f}", walldiff);
    write_meta("user-time", "{:.3f}", userdiff);
    write_meta("sys-time", "{:.3f}", sysdiff);
    write_meta("cpu-time", "{:.3f}", cpudiff);

    logmsg(LOG_DEBUG, "runtime is {:.3f} seconds real, {:.3f} user, {:.3f} sys",
           walldiff, userdiff, sysdiff);

    if (use_walltime && walldiff > walltimelimit[0]) {
        walllimit_reached |= soft_timelimit;
        warning(0, "timelimit exceeded (soft wall time)");
    }

    if (use_cputime && cpudiff > cputimelimit[0]) {
        cpulimit_reached |= soft_timelimit;
        warning(0, "timelimit exceeded (soft cpu time)");
    }

    int timelimit_reached = 0;
    switch (outputtimetype) {
    case WALL_TIME_TYPE:
        write_meta("time-used", "wall-time");
        timelimit_reached = walllimit_reached;
        break;
    case CPU_TIME_TYPE:
        write_meta("time-used", "cpu-time");
        timelimit_reached = cpulimit_reached;
        break;
    default:
        die(0, "cannot write unknown time type `{}' to file", outputtimetype);
    }

    /* Hard limit reached always has precedence. */
    if ((walllimit_reached | cpulimit_reached) & hard_timelimit) {
        timelimit_reached |= hard_timelimit;
    }

    write_meta("time-result", "{}", output_timelimit_str[timelimit_reached]);
}

/* =========== Interaction Logging (using ring buffer) =========== */

InteractionLog interaction_log;

/* =========== Non-Interactive Mode =========== */

int run_non_interactive()
{
    logmsg(LOG_DEBUG, "running in non-interactive mode");

    /* Setup pipes connecting to child stdout/err streams (ignore stdin). */
    for (int i = 1; i <= 2; i++) {
        if (pipe(child_pipefd[i]) != 0) die(errno, "creating pipe for fd {}", i);
    }

    switch (child_pid = fork()) {
    case -1: /* error */
        die(errno, "cannot fork");

    case 0: /* run controlled command */
        /* Apply all restrictions for child process. */
        setrestrictions();
        logmsg(LOG_DEBUG, "setrestrictions() done");

        /* Connect pipes to command stdout/stderr and close unneeded fd's. */
        for (int i = 1; i <= 2; i++) {
            if (dup2(child_pipefd[i][PIPE_IN], i) < 0) {
                die(errno, "redirecting child fd {}", i);
            }
            if (close(child_pipefd[i][PIPE_IN]) != 0 ||
                close(child_pipefd[i][PIPE_OUT]) != 0) {
                die(errno, "closing pipe for fd {}", i);
            }
        }
        logmsg(LOG_DEBUG, "pipes closed in child");

        if (outputmeta) {
            if (fclose(metafile) != 0) {
                die(errno, "closing file `{}'", metafilename);
            }
            logmsg(LOG_DEBUG, "metafile closed in child");
        }

        /* And execute child command. */
        execvp(cmdname, cmdargs);
        die(errno, "cannot start `{}' as user `{}'", cmdname, username());

    default: /* become watchdog */
        logmsg(LOG_DEBUG, "child pid = {}", child_pid);
        program_state.pid = child_pid;

        /* Shed privileges if not using a separate child uid. */
        if (!use_user) {
            if (setuid(getuid()) != 0) die(errno, "setting watchdog uid");
            logmsg(LOG_DEBUG, "watchdog using user ID `{}'", getuid());
        }

        if (gettimeofday(&starttime, nullptr)) die(errno, "getting time");

        /* Close unused file descriptors */
        for (int i = 1; i <= 2; i++) {
            if (close(child_pipefd[i][PIPE_IN]) != 0) {
                die(errno, "closing pipe for fd {}", i);
            }
        }

        /* Redirect child stdout/stderr to file */
        for (int i = 1; i <= 2; i++) {
            child_redirfd[i] = i; /* Default: no redirects */
        }

        size_t data_read[3] = {0, 0, 0};
        size_t data_passed[3] = {0, 0, 0};

        if (redir_stdout) {
            child_redirfd[STDOUT_FILENO] = creat(stdoutfilename, S_IRUSR | S_IWUSR);
            if (child_redirfd[STDOUT_FILENO] < 0) {
                die(errno, "opening file `{}'", stdoutfilename);
            }
        }
        if (redir_stderr) {
            child_redirfd[STDERR_FILENO] = creat(stderrfilename, S_IRUSR | S_IWUSR);
            if (child_redirfd[STDERR_FILENO] < 0) {
                die(errno, "opening file `{}'", stderrfilename);
            }
        }
        logmsg(LOG_DEBUG, "redirection done in parent");

        /* Set up epoll */
        epoll_fd = epoll_create1(O_CLOEXEC);
        if (epoll_fd < 0) die(errno, "creating epoll");

        struct epoll_event ev;
        ev.events = EPOLLIN;

        /* Add signal pipe to epoll */
        ev.data.fd = signal_pipe[PIPE_OUT];
        if (epoll_ctl(epoll_fd, EPOLL_CTL_ADD, signal_pipe[PIPE_OUT], &ev) < 0) {
            die(errno, "adding signal pipe to epoll");
        }

        /* Add child output pipes to epoll */
        for (int i = 1; i <= 2; i++) {
            set_non_blocking(child_pipefd[i][PIPE_OUT]);
            ev.data.fd = child_pipefd[i][PIPE_OUT];
            if (epoll_ctl(epoll_fd, EPOLL_CTL_ADD, child_pipefd[i][PIPE_OUT], &ev) < 0) {
                die(errno, "adding child pipe {} to epoll", i);
            }
        }

        if (use_walltime) {
            struct itimerval itimer;
            itimer.it_interval.tv_sec = 0;
            itimer.it_interval.tv_usec = 0;
            double tmpd;
            itimer.it_value.tv_sec = (int)walltimelimit[1];
            itimer.it_value.tv_usec = (int)(modf(walltimelimit[1], &tmpd) * 1E6);

            if (setitimer(ITIMER_REAL, &itimer, nullptr) != 0) {
                die(errno, "setting timer");
            }
            logmsg(LOG_DEBUG, "setting hard wall-time limit to {:.3f} seconds", walltimelimit[1]);
        }

        if (times(&startticks) == (clock_t)-1) {
            die(errno, "getting start clock ticks");
        }

        /* Epoll loop */
        int status = 0;
        bool child_exited = false;
        int open_pipes = 2;

        struct epoll_event events[EPOLL_MAX_EVENTS];
        char buf[BUF_SIZE];

        while (!child_exited || open_pipes > 0) {
            int nfds = epoll_wait(epoll_fd, events, EPOLL_MAX_EVENTS, -1);
            if (nfds < 0) {
                if (errno == EINTR) continue;
                die(errno, "epoll_wait failed");
            }

            for (int n = 0; n < nfds; n++) {
                int fd = events[n].data.fd;

                /* Signal pipe */
                if (fd == signal_pipe[PIPE_OUT]) {
                    char sigbuf[16];
                    ssize_t r = read(signal_pipe[PIPE_OUT], sigbuf, sizeof(sigbuf));
                    if (r > 0) {
                        for (ssize_t i = 0; i < r; i++) {
                            int sig = sigbuf[i];
                            if (sig == SIGCHLD) {
                                int wait_status;
                                pid_t pid = waitpid(-1, &wait_status, WNOHANG);
                                if (pid == child_pid) {
                                    child_exited = true;
                                    status = wait_status;
                                    program_state.exited = true;
                                    program_state.exit_status = status;
                                }
                            } else if (sig == SIGALRM) {
                                walllimit_reached |= hard_timelimit;
                                warning(0, "timelimit exceeded (hard wall time): aborting command");
                                logmsg(LOG_DEBUG, "sending SIGTERM to child");
                                if (kill(-child_pid, SIGTERM) != 0 && errno != ESRCH) {
                                    warning(errno, "error sending SIGTERM to command");
                                }
                                nanosleep(&killdelay, nullptr);
                                logmsg(LOG_DEBUG, "sending SIGKILL to child");
                                if (kill(-child_pid, SIGKILL) != 0 && errno != ESRCH) {
                                    warning(errno, "error sending SIGKILL to command");
                                }
                            } else if (sig == SIGTERM) {
                                warning(0, "received SIGTERM: aborting command");
                                if (kill(-child_pid, SIGTERM) != 0 && errno != ESRCH) {
                                    warning(errno, "error sending SIGTERM to command");
                                }
                                nanosleep(&killdelay, nullptr);
                                if (kill(-child_pid, SIGKILL) != 0 && errno != ESRCH) {
                                    warning(errno, "error sending SIGKILL to command");
                                }
                            }
                        }
                    }
                    continue;
                }

                /* Child output pipes */
                for (int i = 1; i <= 2; i++) {
                    if (fd == child_pipefd[i][PIPE_OUT]) {
                        while (true) {
                            size_t to_read = BUF_SIZE;
                            if (limit_streamsize && data_passed[i] < streamsize) {
                                to_read = std::min(to_read, streamsize - data_passed[i]);
                            } else if (limit_streamsize) {
                                /* Just consume and discard */
                                to_read = BUF_SIZE;
                            }

                            ssize_t nread = read(child_pipefd[i][PIPE_OUT], buf, to_read);
                            if (nread < 0) {
                                if (errno == EAGAIN || errno == EWOULDBLOCK) break;
                                if (errno == EINTR) continue;
                                die(errno, "reading from child pipe {}", i);
                            }
                            if (nread == 0) {
                                /* EOF: close the pipe */
                                epoll_ctl(epoll_fd, EPOLL_CTL_DEL, child_pipefd[i][PIPE_OUT], nullptr);
                                close(child_pipefd[i][PIPE_OUT]);
                                child_pipefd[i][PIPE_OUT] = -1;
                                open_pipes--;
                                break;
                            }

                            data_read[i] += nread;
                            if (!limit_streamsize || data_passed[i] < streamsize) {
                                size_t to_write = nread;
                                if (limit_streamsize) {
                                    to_write = std::min((size_t)nread, streamsize - data_passed[i]);
                                }
                                ssize_t written = write_all(child_redirfd[i], buf, to_write);
                                if (written > 0) data_passed[i] += written;
                            }
                        }
                        break;
                    }
                }
            }
        }

        /* Close the output files */
        for (int i = 1; i <= 2; i++) {
            if (child_redirfd[i] != i && close(child_redirfd[i]) != 0) {
                die(errno, "closing output fd {}", i);
            }
        }

        if (times(&endticks) == (clock_t)-1) {
            die(errno, "getting end clock ticks");
        }

        if (gettimeofday(&endtime, nullptr)) die(errno, "getting time");

        /* Disarm timer */
        if (use_walltime) {
            struct itimerval itimer = {};
            if (setitimer(ITIMER_REAL, &itimer, nullptr) != 0) {
                die(errno, "disarming timer");
            }
        }

        /* Test whether command has finished abnormally */
        int exitcode = 0;
        if (!WIFEXITED(status)) {
            if (WIFSIGNALED(status)) {
                if (WTERMSIG(status) == SIGXCPU) {
                    cpulimit_reached |= hard_timelimit;
                    warning(0, "timelimit exceeded (hard cpu time)");
                } else {
                    warning(0, "command terminated with signal {}", WTERMSIG(status));
                }
                exitcode = 128 + WTERMSIG(status);
            } else if (WIFSTOPPED(status)) {
                warning(0, "command stopped with signal {}", WSTOPSIG(status));
                exitcode = 128 + WSTOPSIG(status);
            } else {
                die(0, "command exit status unknown: {}", status);
            }
        } else {
            exitcode = WEXITSTATUS(status);
        }
        logmsg(LOG_DEBUG, "child exited with exit code {}", exitcode);

        check_remaining_procs();

        double cputime = -1;
        output_cgroup_stats(&cputime);
        cgroup_kill();
        cgroup_delete();

        /* Drop root before writing to output file(s). */
        if (setuid(getuid()) != 0) die(errno, "dropping root privileges");

        output_exit_time(exitcode, cputime);

        /* Check if the output stream was truncated. */
        if (limit_streamsize) {
            std::string truncated;
            if (data_passed[1] < data_read[1]) {
                truncated += "stdout";
            }
            if (data_passed[2] < data_read[2]) {
                if (!truncated.empty()) truncated += ",";
                truncated += "stderr";
            }
            write_meta("output-truncated", "{}", truncated);
        }

        write_meta("stdin-bytes", "{}", data_read[0]);
        write_meta("stdout-bytes", "{}", data_read[1]);
        write_meta("stderr-bytes", "{}", data_read[2]);

        if (outputmeta && fclose(metafile) != 0) {
            die(errno, "closing file `{}'", metafilename);
        }

        close(epoll_fd);

        return exitcode;
    }

    /* This should never be reached */
    die(0, "unexpected end of program");
}

/* =========== Interactive Mode =========== */

/* Parse the validator command into program and arguments */
std::vector<std::string> parse_command(const char *cmd)
{
    std::vector<std::string> result;
    std::string current;
    bool in_quotes = false;
    bool in_double_quotes = false;
    bool escape = false;

    for (const char *p = cmd; *p; p++) {
        if (escape) {
            current += *p;
            escape = false;
        } else if (*p == '\\') {
            escape = true;
        } else if (*p == '\'' && !in_double_quotes) {
            in_quotes = !in_quotes;
        } else if (*p == '"' && !in_quotes) {
            in_double_quotes = !in_double_quotes;
        } else if (isspace(*p) && !in_quotes && !in_double_quotes) {
            if (!current.empty()) {
                result.push_back(current);
                current.clear();
            }
        } else {
            current += *p;
        }
    }
    if (!current.empty()) {
        result.push_back(current);
    }
    return result;
}

int run_interactive()
{
    logmsg(LOG_DEBUG, "running in interactive mode");

    if (validator_cmd == nullptr || strlen(validator_cmd) == 0) {
        die(0, "validator command required for interactive mode");
    }

    /* Parse validator command */
    std::vector<std::string> val_args = parse_command(validator_cmd);
    if (val_args.empty()) {
        die(0, "empty validator command");
    }
    std::string val_cmd = val_args[0];
    val_args.erase(val_args.begin());

    /* Open interaction log file and initialize ring buffer */
    if (interaction_logfilename != nullptr) {
        interaction_logfd = open(interaction_logfilename,
                                  O_CREAT | O_WRONLY | O_TRUNC | O_CLOEXEC,
                                  S_IRUSR | S_IWUSR | S_IRGRP | S_IWGRP);
        if (interaction_logfd < 0) {
            die(errno, "opening interaction log file `{}'", interaction_logfilename);
        }
        interaction_log.init(interaction_logfd);
    }

    /* Open validator meta file */
    if (validator_metafilename != nullptr) {
        validator_metafile = fopen(validator_metafilename, "w");
        if (validator_metafile == nullptr) {
            die(errno, "opening validator meta file `{}'", validator_metafilename);
        }
    }

    /*
     * Pipe architecture for proxying with logging:
     *
     * Program                 Parent (proxy)              Validator
     * -------                 --------------              ---------
     * stdout --> prog_out_pipe --> [log] --> val_in_pipe --> stdin
     * stdin  <-- prog_in_pipe  <-- [log] <-- val_out_pipe <-- stdout
     * stderr --> prog_err_pipe --> [file]
     *                                        val_err_pipe <-- stderr
     *
     * The parent reads from program stdout, logs it, writes to validator stdin.
     * The parent reads from validator stdout, logs it, writes to program stdin.
     */

    int prog_out_pipe[2], prog_in_pipe[2], prog_err_pipe[2];
    int val_out_pipe[2], val_in_pipe[2], val_err_pipe[2];

    if (pipe2(prog_out_pipe, O_CLOEXEC) != 0) die(errno, "creating prog_out pipe");
    if (pipe2(prog_in_pipe, O_CLOEXEC) != 0) die(errno, "creating prog_in pipe");
    if (pipe2(prog_err_pipe, O_CLOEXEC) != 0) die(errno, "creating prog_err pipe");
    if (pipe2(val_out_pipe, O_CLOEXEC) != 0) die(errno, "creating val_out pipe");
    if (pipe2(val_in_pipe, O_CLOEXEC) != 0) die(errno, "creating val_in pipe");
    if (pipe2(val_err_pipe, O_CLOEXEC) != 0) die(errno, "creating val_err pipe");

    /* Maximize pipe buffer sizes for better throughput */
    resize_pipe(prog_out_pipe[PIPE_OUT]);
    resize_pipe(prog_in_pipe[PIPE_IN]);
    resize_pipe(val_out_pipe[PIPE_OUT]);
    resize_pipe(val_in_pipe[PIPE_IN]);

    /* Fork program child */
    switch (child_pid = fork()) {
    case -1:
        die(errno, "cannot fork program");

    case 0: /* child: run program */
        /* Apply restrictions */
        setrestrictions();

        /* Set up I/O */
        if (dup2(prog_in_pipe[PIPE_OUT], STDIN_FILENO) < 0) {
            die(errno, "redirecting program stdin");
        }
        if (dup2(prog_out_pipe[PIPE_IN], STDOUT_FILENO) < 0) {
            die(errno, "redirecting program stdout");
        }
        if (dup2(prog_err_pipe[PIPE_IN], STDERR_FILENO) < 0) {
            die(errno, "redirecting program stderr");
        }

        /* Close all pipe ends */
        close(prog_out_pipe[PIPE_OUT]); close(prog_out_pipe[PIPE_IN]);
        close(prog_in_pipe[PIPE_OUT]); close(prog_in_pipe[PIPE_IN]);
        close(prog_err_pipe[PIPE_OUT]); close(prog_err_pipe[PIPE_IN]);
        close(val_out_pipe[PIPE_OUT]); close(val_out_pipe[PIPE_IN]);
        close(val_in_pipe[PIPE_OUT]); close(val_in_pipe[PIPE_IN]);
        close(val_err_pipe[PIPE_OUT]); close(val_err_pipe[PIPE_IN]);

        if (outputmeta && fclose(metafile) != 0) {
            die(errno, "closing metafile in child");
        }
        if (validator_metafile && fclose(validator_metafile) != 0) {
            die(errno, "closing validator metafile in child");
        }

        execvp(cmdname, cmdargs);
        die(errno, "cannot start program `{}'", cmdname);
    }

    logmsg(LOG_DEBUG, "program child pid = {}", child_pid);
    program_state.pid = child_pid;
    program_state.is_program = true;

    /* Fork validator child */
    switch (validator_pid = fork()) {
    case -1:
        die(errno, "cannot fork validator");

    case 0: /* child: run validator */
        /* Apply restrictions (same as program) */
        setrestrictions();

        /* Set up I/O */
        if (dup2(val_in_pipe[PIPE_OUT], STDIN_FILENO) < 0) {
            die(errno, "redirecting validator stdin");
        }
        if (dup2(val_out_pipe[PIPE_IN], STDOUT_FILENO) < 0) {
            die(errno, "redirecting validator stdout");
        }
        if (dup2(val_err_pipe[PIPE_IN], STDERR_FILENO) < 0) {
            die(errno, "redirecting validator stderr");
        }

        /* Close all pipe ends */
        close(prog_out_pipe[PIPE_OUT]); close(prog_out_pipe[PIPE_IN]);
        close(prog_in_pipe[PIPE_OUT]); close(prog_in_pipe[PIPE_IN]);
        close(prog_err_pipe[PIPE_OUT]); close(prog_err_pipe[PIPE_IN]);
        close(val_out_pipe[PIPE_OUT]); close(val_out_pipe[PIPE_IN]);
        close(val_in_pipe[PIPE_OUT]); close(val_in_pipe[PIPE_IN]);
        close(val_err_pipe[PIPE_OUT]); close(val_err_pipe[PIPE_IN]);

        if (outputmeta && fclose(metafile) != 0) {
            die(errno, "closing metafile in validator child");
        }
        if (validator_metafile && fclose(validator_metafile) != 0) {
            die(errno, "closing validator metafile in validator child");
        }

        /* Build argv for validator */
        std::vector<char *> argv;
        argv.push_back(const_cast<char *>(val_cmd.c_str()));
        for (auto &arg : val_args) {
            argv.push_back(const_cast<char *>(arg.c_str()));
        }
        argv.push_back(nullptr);

        execvp(val_cmd.c_str(), argv.data());
        die(errno, "cannot start validator `{}'", val_cmd);
    }

    logmsg(LOG_DEBUG, "validator child pid = {}", validator_pid);
    validator_state.pid = validator_pid;
    validator_state.is_program = false;

    /* Parent: close pipe ends used by children */
    close(prog_out_pipe[PIPE_IN]);   /* program writes here */
    close(prog_in_pipe[PIPE_OUT]);   /* program reads here */
    close(prog_err_pipe[PIPE_IN]);   /* program writes here */
    close(val_out_pipe[PIPE_IN]);    /* validator writes here */
    close(val_in_pipe[PIPE_OUT]);    /* validator reads here */
    close(val_err_pipe[PIPE_IN]);    /* validator writes here */

    /* Parent keeps:
       - prog_out_pipe[PIPE_OUT]: read program stdout
       - prog_in_pipe[PIPE_IN]:   write to program stdin
       - prog_err_pipe[PIPE_OUT]: read program stderr
       - val_out_pipe[PIPE_OUT]:  read validator stdout
       - val_in_pipe[PIPE_IN]:    write to validator stdin
       - val_err_pipe[PIPE_OUT]:  read validator stderr
    */

    /* Shed privileges */
    if (!use_user) {
        if (setuid(getuid()) != 0) die(errno, "setting watchdog uid");
        logmsg(LOG_DEBUG, "watchdog using user ID `{}'", getuid());
    }

    if (gettimeofday(&starttime, nullptr)) die(errno, "getting time");

    /* Set non-blocking on all read fds */
    set_non_blocking(prog_out_pipe[PIPE_OUT]);
    set_non_blocking(prog_err_pipe[PIPE_OUT]);
    set_non_blocking(val_out_pipe[PIPE_OUT]);
    set_non_blocking(val_err_pipe[PIPE_OUT]);

    /* Set up epoll */
    epoll_fd = epoll_create1(O_CLOEXEC);
    if (epoll_fd < 0) die(errno, "creating epoll");

    struct epoll_event ev;
    ev.events = EPOLLIN;

    /* Add signal pipe */
    ev.data.fd = signal_pipe[PIPE_OUT];
    if (epoll_ctl(epoll_fd, EPOLL_CTL_ADD, signal_pipe[PIPE_OUT], &ev) < 0) {
        die(errno, "adding signal pipe to epoll");
    }

    /* Track FDs for data pumping */
    const int FD_PROG_OUT = prog_out_pipe[PIPE_OUT];
    const int FD_PROG_ERR = prog_err_pipe[PIPE_OUT];
    const int FD_VAL_OUT = val_out_pipe[PIPE_OUT];
    const int FD_VAL_ERR = val_err_pipe[PIPE_OUT];
    const int FD_PROG_IN = prog_in_pipe[PIPE_IN];
    const int FD_VAL_IN = val_in_pipe[PIPE_IN];

    ev.data.fd = FD_PROG_OUT;
    if (epoll_ctl(epoll_fd, EPOLL_CTL_ADD, FD_PROG_OUT, &ev) < 0) {
        die(errno, "adding prog_out to epoll");
    }
    ev.data.fd = FD_PROG_ERR;
    if (epoll_ctl(epoll_fd, EPOLL_CTL_ADD, FD_PROG_ERR, &ev) < 0) {
        die(errno, "adding prog_err to epoll");
    }
    ev.data.fd = FD_VAL_OUT;
    if (epoll_ctl(epoll_fd, EPOLL_CTL_ADD, FD_VAL_OUT, &ev) < 0) {
        die(errno, "adding val_out to epoll");
    }
    ev.data.fd = FD_VAL_ERR;
    if (epoll_ctl(epoll_fd, EPOLL_CTL_ADD, FD_VAL_ERR, &ev) < 0) {
        die(errno, "adding val_err to epoll");
    }

    /* Open stderr output file */
    int prog_stderr_fd = STDERR_FILENO;
    if (redir_stderr) {
        prog_stderr_fd = creat(stderrfilename, S_IRUSR | S_IWUSR);
        if (prog_stderr_fd < 0) {
            die(errno, "opening file `{}'", stderrfilename);
        }
    }

    if (use_walltime) {
        struct itimerval itimer;
        itimer.it_interval.tv_sec = 0;
        itimer.it_interval.tv_usec = 0;
        double tmpd;
        itimer.it_value.tv_sec = (int)walltimelimit[1];
        itimer.it_value.tv_usec = (int)(modf(walltimelimit[1], &tmpd) * 1E6);

        if (setitimer(ITIMER_REAL, &itimer, nullptr) != 0) {
            die(errno, "setting timer");
        }
        logmsg(LOG_DEBUG, "setting hard wall-time limit to {:.3f} seconds", walltimelimit[1]);
    }

    if (times(&startticks) == (clock_t)-1) {
        die(errno, "getting start clock ticks");
    }

    /* State tracking */
    bool timelimit_triggered = false;
    bool prog_out_open = true, prog_err_open = true;
    bool val_out_open = true, val_err_open = true;
    bool prog_in_open = true, val_in_open = true;
    size_t prog_stderr_bytes = 0;
    size_t val_stderr_bytes = 0;
    size_t prog_to_val_bytes = 0;
    size_t val_to_prog_bytes = 0;
    pid_t first_exit_pid = -1;

    struct epoll_event events[EPOLL_MAX_EVENTS];
    char buf[BUF_SIZE];

    /* Main event loop */
    while (prog_out_open || prog_err_open || val_out_open || val_err_open ||
           !program_state.exited || !validator_state.exited) {

        int nfds = epoll_wait(epoll_fd, events, EPOLL_MAX_EVENTS, 100);
        if (nfds < 0) {
            if (errno == EINTR) continue;
            die(errno, "epoll_wait failed");
        }

        for (int n = 0; n < nfds; n++) {
            int fd = events[n].data.fd;

            /* Signal pipe */
            if (fd == signal_pipe[PIPE_OUT]) {
                char sigbuf[16];
                ssize_t r = read(signal_pipe[PIPE_OUT], sigbuf, sizeof(sigbuf));
                if (r > 0) {
                    for (ssize_t i = 0; i < r; i++) {
                        int sig = sigbuf[i];
                        if (sig == SIGCHLD) {
                            while (true) {
                                int wait_status;
                                pid_t pid = waitpid(-1, &wait_status, WNOHANG);
                                if (pid <= 0) break;

                                if (pid == child_pid) {
                                    program_state.exited = true;
                                    program_state.exit_status = wait_status;
                                    logmsg(LOG_DEBUG, "program exited with status {}", wait_status);
                                    if (first_exit_pid == -1 && !timelimit_triggered) {
                                        first_exit_pid = pid;
                                    }
                                    /* Close write end to program since it's gone */
                                    if (prog_in_open) {
                                        close(FD_PROG_IN);
                                        prog_in_open = false;
                                    }
                                } else if (pid == validator_pid) {
                                    validator_state.exited = true;
                                    validator_state.exit_status = wait_status;
                                    logmsg(LOG_DEBUG, "validator exited with status {}", wait_status);
                                    if (first_exit_pid == -1 && !timelimit_triggered) {
                                        first_exit_pid = pid;
                                    }
                                    /* Close write end to validator since it's gone */
                                    if (val_in_open) {
                                        close(FD_VAL_IN);
                                        val_in_open = false;
                                    }
                                }
                            }
                        } else if (sig == SIGALRM) {
                            walllimit_reached |= hard_timelimit;
                            timelimit_triggered = true;
                            warning(0, "timelimit exceeded (hard wall time): aborting");

                            logmsg(LOG_DEBUG, "sending SIGTERM to program");
                            if (kill(-child_pid, SIGTERM) != 0 && errno != ESRCH) {
                                warning(errno, "error sending SIGTERM to program");
                            }
                            logmsg(LOG_DEBUG, "sending SIGTERM to validator");
                            if (kill(-validator_pid, SIGTERM) != 0 && errno != ESRCH) {
                                warning(errno, "error sending SIGTERM to validator");
                            }
                            nanosleep(&killdelay, nullptr);
                            logmsg(LOG_DEBUG, "sending SIGKILL to program");
                            if (kill(-child_pid, SIGKILL) != 0 && errno != ESRCH) {
                                warning(errno, "error sending SIGKILL to program");
                            }
                            logmsg(LOG_DEBUG, "sending SIGKILL to validator");
                            if (kill(-validator_pid, SIGKILL) != 0 && errno != ESRCH) {
                                warning(errno, "error sending SIGKILL to validator");
                            }
                        } else if (sig == SIGTERM) {
                            warning(0, "received SIGTERM: aborting");
                            kill(-child_pid, SIGTERM);
                            kill(-validator_pid, SIGTERM);
                            nanosleep(&killdelay, nullptr);
                            kill(-child_pid, SIGKILL);
                            kill(-validator_pid, SIGKILL);
                        }
                    }
                }
                continue;
            }

            /* Program stdout -> log -> validator stdin */
            if (fd == FD_PROG_OUT && prog_out_open) {
                while (true) {
                    ssize_t nread = read(FD_PROG_OUT, buf, BUF_SIZE);
                    if (nread < 0) {
                        if (errno == EAGAIN || errno == EWOULDBLOCK) break;
                        if (errno == EINTR) continue;
                        die(errno, "reading program stdout");
                    }
                    if (nread == 0) {
                        /* EOF from program */
                        epoll_ctl(epoll_fd, EPOLL_CTL_DEL, FD_PROG_OUT, nullptr);
                        close(FD_PROG_OUT);
                        prog_out_open = false;
                        /* Close validator stdin to signal EOF */
                        if (val_in_open) {
                            close(FD_VAL_IN);
                            val_in_open = false;
                        }
                        interaction_log.add_eof(true);
                        break;
                    }
                    /* Log and forward to validator */
                    interaction_log.add_entry(buf, nread, true);
                    prog_to_val_bytes += nread;
                    if (val_in_open) {
                        write_all(FD_VAL_IN, buf, nread);
                    }
                }
                continue;
            }

            /* Validator stdout -> log -> program stdin */
            if (fd == FD_VAL_OUT && val_out_open) {
                while (true) {
                    ssize_t nread = read(FD_VAL_OUT, buf, BUF_SIZE);
                    if (nread < 0) {
                        if (errno == EAGAIN || errno == EWOULDBLOCK) break;
                        if (errno == EINTR) continue;
                        die(errno, "reading validator stdout");
                    }
                    if (nread == 0) {
                        /* EOF from validator */
                        epoll_ctl(epoll_fd, EPOLL_CTL_DEL, FD_VAL_OUT, nullptr);
                        close(FD_VAL_OUT);
                        val_out_open = false;
                        /* Close program stdin to signal EOF */
                        if (prog_in_open) {
                            close(FD_PROG_IN);
                            prog_in_open = false;
                        }
                        interaction_log.add_eof(false);
                        break;
                    }
                    /* Log and forward to program */
                    interaction_log.add_entry(buf, nread, false);
                    val_to_prog_bytes += nread;
                    if (prog_in_open) {
                        write_all(FD_PROG_IN, buf, nread);
                    }
                }
                continue;
            }

            /* Program stderr */
            if (fd == FD_PROG_ERR && prog_err_open) {
                while (true) {
                    ssize_t nread = read(FD_PROG_ERR, buf, BUF_SIZE);
                    if (nread < 0) {
                        if (errno == EAGAIN || errno == EWOULDBLOCK) break;
                        if (errno == EINTR) continue;
                        die(errno, "reading program stderr");
                    }
                    if (nread == 0) {
                        epoll_ctl(epoll_fd, EPOLL_CTL_DEL, FD_PROG_ERR, nullptr);
                        close(FD_PROG_ERR);
                        prog_err_open = false;
                        break;
                    }
                    prog_stderr_bytes += nread;
                    if (prog_stderr_fd >= 0) {
                        write_all(prog_stderr_fd, buf, nread);
                    }
                }
                continue;
            }

            /* Validator stderr */
            if (fd == FD_VAL_ERR && val_err_open) {
                while (true) {
                    ssize_t nread = read(FD_VAL_ERR, buf, BUF_SIZE);
                    if (nread < 0) {
                        if (errno == EAGAIN || errno == EWOULDBLOCK) break;
                        if (errno == EINTR) continue;
                        die(errno, "reading validator stderr");
                    }
                    if (nread == 0) {
                        epoll_ctl(epoll_fd, EPOLL_CTL_DEL, FD_VAL_ERR, nullptr);
                        close(FD_VAL_ERR);
                        val_err_open = false;
                        break;
                    }
                    val_stderr_bytes += nread;
                    logmsg(LOG_DEBUG, "validator stderr ({} bytes)", nread);
                }
                continue;
            }
        }

        /* Exit early if both processes exited and all pipes closed */
        if (program_state.exited && validator_state.exited &&
            !prog_out_open && !prog_err_open && !val_out_open && !val_err_open) {
            break;
        }
    }

    /* Flush interaction log to file */
    interaction_log.flush();

    /* Close remaining fds */
    if (prog_out_open) close(FD_PROG_OUT);
    if (prog_err_open) close(FD_PROG_ERR);
    if (val_out_open) close(FD_VAL_OUT);
    if (val_err_open) close(FD_VAL_ERR);
    if (prog_in_open) close(FD_PROG_IN);
    if (val_in_open) close(FD_VAL_IN);

    /* Close stderr output */
    if (prog_stderr_fd >= 0 && prog_stderr_fd != STDERR_FILENO) {
        close(prog_stderr_fd);
    }

    if (times(&endticks) == (clock_t)-1) {
        die(errno, "getting end clock ticks");
    }

    if (gettimeofday(&endtime, nullptr)) die(errno, "getting time");

    /* Disarm timer */
    if (use_walltime) {
        struct itimerval itimer = {};
        if (setitimer(ITIMER_REAL, &itimer, nullptr) != 0) {
            die(errno, "disarming timer");
        }
    }

    /* Determine exit codes */
    int prog_exitcode = 0;
    if (!WIFEXITED(program_state.exit_status)) {
        if (WIFSIGNALED(program_state.exit_status)) {
            if (WTERMSIG(program_state.exit_status) == SIGXCPU) {
                cpulimit_reached |= hard_timelimit;
                warning(0, "timelimit exceeded (hard cpu time)");
            } else {
                warning(0, "program terminated with signal {}", WTERMSIG(program_state.exit_status));
            }
            prog_exitcode = 128 + WTERMSIG(program_state.exit_status);
        } else if (WIFSTOPPED(program_state.exit_status)) {
            warning(0, "program stopped with signal {}", WSTOPSIG(program_state.exit_status));
            prog_exitcode = 128 + WSTOPSIG(program_state.exit_status);
        }
    } else {
        prog_exitcode = WEXITSTATUS(program_state.exit_status);
    }

    int val_exitcode = 0;
    if (!WIFEXITED(validator_state.exit_status)) {
        if (WIFSIGNALED(validator_state.exit_status)) {
            val_exitcode = 128 + WTERMSIG(validator_state.exit_status);
            warning(0, "validator terminated with signal {}", WTERMSIG(validator_state.exit_status));
        } else if (WIFSTOPPED(validator_state.exit_status)) {
            val_exitcode = 128 + WSTOPSIG(validator_state.exit_status);
        }
    } else {
        val_exitcode = WEXITSTATUS(validator_state.exit_status);
    }

    logmsg(LOG_DEBUG, "program exitcode = {}, validator exitcode = {}", prog_exitcode, val_exitcode);
    logmsg(LOG_DEBUG, "bytes transferred: prog->val={}, val->prog={}", prog_to_val_bytes, val_to_prog_bytes);

    check_remaining_procs();

    double cputime = -1;
    output_cgroup_stats(&cputime);
    cgroup_kill();
    cgroup_delete();

    /* Drop root before writing output */
    if (setuid(getuid()) != 0) die(errno, "dropping root privileges");

    /* Write program metadata */
    output_exit_time(prog_exitcode, cputime);
    write_meta("stderr-bytes", "{}", prog_stderr_bytes);
    write_meta("validator-exited-first", "{}", (first_exit_pid == validator_pid) ? "true" : "false");
    write_meta("bytes-transferred", "{}", prog_to_val_bytes + val_to_prog_bytes);

    if (outputmeta && fclose(metafile) != 0) {
        die(errno, "closing file `{}'", metafilename);
    }

    /* Write validator metadata */
    write_validator_meta("exitcode", "{}", val_exitcode);
    if (validator_metafile && fclose(validator_metafile) != 0) {
        die(errno, "closing validator meta file `{}'", validator_metafilename);
    }

    /* Close interaction log */
    if (interaction_logfd >= 0) {
        close(interaction_logfd);
    }

    close(epoll_fd);

    return prog_exitcode;
}

/* =========== Main =========== */

int main(int argc, char **argv)
{
    int ret;
    regex_t userregex;
    int opt;
    char str[256];

    progname = argv[0];

    if (gettimeofday(&progstarttime, nullptr)) die(errno, "getting time");

    /* Parse command-line options */
    use_root = use_walltime = use_cputime = use_user = no_coredump = 0;
    outputmeta = walllimit_reached = cpulimit_reached = 0;
    outputtimetype = CPU_TIME_TYPE;
    preserve_environment = 0;
    memsize = filesize = nproc = RLIM_INFINITY;
    redir_stdout = redir_stderr = limit_streamsize = 0;
    show_help = show_version = 0;
    interactive_mode = 0;
    opterr = 0;

    char *ptr;
    while ((opt = getopt_long(argc, argv, "+r:u:g:d:t:C:m:f:p:P:co:e:s:EV:M:vqIX:Y:L:",
                               long_opts, nullptr)) != -1) {
        switch (opt) {
        case 0:   /* long-only option */
            break;
        case 'r': /* rootdir option */
            use_root = 1;
            rootdir = (char *)malloc(strlen(optarg) + 2);
            if (rootdir == nullptr) die(errno, "allocating memory");
            strcpy(rootdir, optarg);
            break;
        case 'u': /* user option: uid or string */
            use_user = 1;
            runuser = strdup(optarg);
            if (runuser == nullptr) die(errno, "strdup() failed");
            errno = 0;
            runuid = strtol(optarg, &ptr, 10);
            if (errno || *ptr != '\0') {
                runuid = userid(optarg);
                if (regcomp(&userregex, "^[A-Za-z][A-Za-z0-9\\._-]*$", REG_NOSUB) != 0) {
                    die(0, "could not create username regex");
                }
                if (regexec(&userregex, runuser, 0, nullptr, 0) != 0) {
                    die(0, "username `{}' does not match POSIX pattern", runuser);
                }
            }
            if (runuid < 0) die(0, "invalid username or ID specified: `{}'", optarg);
            break;
        case 'g': /* group option: gid or string */
            use_group = 1;
            rungroup = strdup(optarg);
            if (rungroup == nullptr) die(errno, "strdup() failed");
            errno = 0;
            rungid = strtol(optarg, &ptr, 10);
            if (errno || *ptr != '\0') rungid = groupid(optarg);
            if (rungid < 0) die(0, "invalid groupname or ID specified: `{}'", optarg);
            break;
        case 'd': /* chdir option */
            rootchdir = (char *)malloc(strlen(optarg) + 2);
            if (rootchdir == nullptr) die(errno, "allocating memory");
            strcpy(rootchdir, optarg);
            break;
        case 't': /* wallclock time option */
            use_walltime = 1;
            outputtimetype = WALL_TIME_TYPE;
            read_optarg_time("walltime", walltimelimit);
            break;
        case 'C': /* CPU time option */
            use_cputime = 1;
            outputtimetype = CPU_TIME_TYPE;
            read_optarg_time("cputime", cputimelimit);
            break;
        case 'm': /* memsize option */
            memsize = (rlim_t)read_optarg_int("memory limit", 1, LONG_MAX);
            if (memsize != (memsize * 1024) / 1024) {
                memsize = RLIM_INFINITY;
            } else {
                memsize *= 1024;
            }
            break;
        case 'f': /* filesize option */
            filesize = (rlim_t)read_optarg_int("filesize limit", 1, LONG_MAX);
            if (filesize != (filesize * 1024) / 1024) {
                filesize = RLIM_INFINITY;
            } else {
                filesize *= 1024;
            }
            break;
        case 'p': /* nproc option */
            nproc = (rlim_t)read_optarg_int("process limit", 1, LONG_MAX);
            break;
        case 'P': /* cpuset option */
            cpuset = optarg;
            break;
        case 'c': /* no-core option */
            no_coredump = 1;
            break;
        case 'o': /* stdout option */
            redir_stdout = 1;
            stdoutfilename = strdup(optarg);
            break;
        case 'e': /* stderr option */
            redir_stderr = 1;
            stderrfilename = strdup(optarg);
            break;
        case 's': /* streamsize option */
            limit_streamsize = 1;
            streamsize = (size_t)read_optarg_int("streamsize limit", 0, LONG_MAX);
            if (streamsize != (streamsize * 1024) / 1024) {
                streamsize = (size_t)LONG_MAX;
            } else {
                streamsize *= 1024;
            }
            break;
        case 'E': /* environment option */
            preserve_environment = 1;
            break;
        case 'V': /* set environment variable */
            environment_variables.push_back(std::string(optarg));
            break;
        case 'M': /* outputmeta option */
            outputmeta = 1;
            metafilename = strdup(optarg);
            break;
        case 'v': /* verbose option */
            verbose = LOG_DEBUG;
            break;
        case 'q': /* quiet option */
            verbose = LOG_ERR;
            break;
        case 'I': /* interactive mode */
            interactive_mode = 1;
            break;
        case 'X': /* validator-cmd */
            validator_cmd = strdup(optarg);
            break;
        case 'Y': /* validator-meta */
            validator_metafilename = strdup(optarg);
            break;
        case 'L': /* interaction-log */
            interaction_logfilename = strdup(optarg);
            break;
        case ':': /* getopt error */
        case '?':
            die(0, "unknown option or missing argument `{}'", optopt);
            break;
        default:
            die(0, "getopt returned character code `{:c}' ??", opt);
        }
    }

    logmsg(LOG_DEBUG, "starting in verbose mode, PID = {}", getpid());

    /* Make sure that we change from group root if we change to an
       unprivileged user to prevent unintended permissions. */
    if (use_user && !use_group) {
        logmsg(LOG_DEBUG, "using unprivileged user `{}' also as group", runuser);
        use_group = 1;
        rungroup = strdup(runuser);
        rungid = groupid(rungroup);
        if (rungid < 0) die(0, "invalid groupname or ID specified: `{}'", rungroup);
    }

    if (show_help) usage();
    if (show_version) version(PROGRAM, VERSION);

    if (argc <= optind) die(0, "no command specified");

    /* Command to be executed */
    cmdname = argv[optind];
    cmdargs = argv + optind;

    if (outputmeta && (metafile = fopen(metafilename, "w")) == nullptr) {
        die(errno, "cannot open `{}'", metafilename);
    }

    /* Check that new uid is in list of valid uid's. */
    if (use_user) {
        char *valid_users = strdup(VALID_USERS);
        for (ptr = strtok(valid_users, ","); ptr != nullptr; ptr = strtok(nullptr, ",")) {
            if (runuid == userid(ptr)) break;
            if (runuser != nullptr) {
                ret = fnmatch(ptr, runuser, 0);
                if (ret == 0) break;
                if (ret != FNM_NOMATCH) {
                    die(0, "matching username `{}' against `{}'", runuser, ptr);
                }
            }
        }
        if (ptr == nullptr || runuid <= 0) die(0, "illegal user specified: {}", runuid);
    }

    install_signal_handlers();

    if (cpuset != nullptr && strlen(cpuset) > 0) {
        std::set<unsigned> cpus = parse_cpuset(cpuset);
        std::set<unsigned> online_cpus = read_cpuset("/sys/devices/system/cpu/online");

        for (unsigned cpu : cpus) {
            if (!online_cpus.count(cpu)) {
                die(0, "requested pinning on CPU {} which is not online", cpu);
            }
        }
    }

    /* Make libcgroup ready for use */
    ret = cgroup_init();
    if (ret != 0) {
        die(0, "libcgroup initialization failed: {}({})", cgroup_strerror(ret), ret);
    }

    /* Define the cgroup name */
    if (cpuset != nullptr && strlen(cpuset) > 0) {
        strncpy(str, cpuset, 16);
    } else {
        str[0] = 0;
    }
    snprintf(cgroupname, 255, "domjudge/dj_cgroup_%d_%.16s_%d.%06d",
             getpid(), str, (int)progstarttime.tv_sec, (int)progstarttime.tv_usec);

    cgroup_create();

    if (unshare(CLONE_FILES | CLONE_FS | CLONE_NEWIPC | CLONE_NEWNET |
                CLONE_NEWNS | CLONE_NEWUTS | CLONE_SYSVSEM) != 0) {
        die(errno, "calling unshare");
    }

    /* Check if any Linux OOM killer adjustments have to be made. */
    FILE *fp = nullptr;
    const char *oom_score_path = "/proc/self/oom_score_adj";
    if ((fp = fopen(oom_score_path, "r+")) != nullptr) {
        if (fscanf(fp, "%d", &ret) != 1) die(errno, "cannot read from `{}'", oom_score_path);
        if (ret < 0) {
            int oom_reset_value = 0;
            logmsg(LOG_DEBUG, "resetting `{}' from {} to {}", oom_score_path, ret, oom_reset_value);
            rewind(fp);
            if (fprintf(fp, "%d\n", oom_reset_value) <= 0) {
                die(errno, "cannot write to `{}'", oom_score_path);
            }
        }
        if (fclose(fp) != 0) die(errno, "closing file `{}'", oom_score_path);
    }

    int exitcode;
    if (interactive_mode) {
        exitcode = run_interactive();
    } else {
        exitcode = run_non_interactive();
    }

    return exitcode;
}
