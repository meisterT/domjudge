/*
 * Common utilities shared between runguard and runguard2.
 *
 * Part of the DOMjudge Programming Contest Jury System and licensed
 * under the GNU GPL. See README and COPYING for details.
 */

#ifndef RUNGUARD_COMMON_H
#define RUNGUARD_COMMON_H

#include <fcntl.h>
#include <unistd.h>
#include <cerrno>
#include <cstdio>

/* Try to resize pipes to their maximum size on Linux. We do this to make it
   as unlikely as possible for either the jury or team program to get blocked
   writing to the other side, if that side doesn't consume data from the pipe.
   See also: https://github.com/Kattis/problemtools/issues/113
 */
static const char *PROC_MAX_PIPE_SIZE = "/proc/sys/fs/pipe-max-size";

inline int get_max_pipe_size() {
    static int max_pipe_size = -1;
    const int FAILED = -2;

    if (max_pipe_size == FAILED) {
        return -1;
    }
    if (max_pipe_size == -1) {
        FILE *f = nullptr;
        if ((f = fopen(PROC_MAX_PIPE_SIZE, "r")) == nullptr) {
            max_pipe_size = FAILED;
            return -1;
        }
        if (fscanf(f, "%d", &max_pipe_size) != 1) {
            max_pipe_size = FAILED;
            fclose(f);
            return -1;
        }
        fclose(f);
    }
    return max_pipe_size;
}

/* Try to resize a pipe to the maximum size. Returns new size or -1 on failure. */
inline int resize_pipe(int fd) {
    int max_size = get_max_pipe_size();
    if (max_size <= 0) {
        return -1;
    }
    return fcntl(fd, F_SETPIPE_SZ, max_size);
}

/* Set the NONBLOCK flag for a file descriptor. Returns 0 on success, -1 on error. */
inline int set_non_blocking(int fd) {
    int flags = fcntl(fd, F_GETFL, 0);
    if (flags == -1) {
        return -1;
    }
    if (fcntl(fd, F_SETFL, flags | O_NONBLOCK) == -1) {
        return -1;
    }
    return 0;
}

/* Write all the data into the file descriptor. It is assumed that the file
   descriptor is blocking. Returns bytes written, or -1 on error. */
inline ssize_t write_all(int fd, const char *data, size_t size) {
    size_t total = 0;
    while (total < size) {
        ssize_t nwrite = write(fd, data + total, size - total);
        if (nwrite < 0) {
            if (errno == EINTR) continue;
            return -1;
        }
        if (nwrite == 0) break;
        total += nwrite;
    }
    return total;
}

/* Array indices for input/output file descriptors as used by pipe() */
#define PIPE_IN  1
#define PIPE_OUT 0

#endif /* RUNGUARD_COMMON_H */
