<?php declare(strict_types=1);
/**
 * Bootstrap file for judge unit tests.
 */

// Prevent the judgedaemon from running when included
define('DOMJUDGE_TESTING', true);

// Define base paths relative to the domjudge root
$domjudgeRoot = dirname(__DIR__, 2);

// Define SCRIPT_ID early so lib.error.php can use it
define('SCRIPT_ID', 'judge-test');

// Define required path constants
define('DOMJUDGE_VERSION', 'test');
define('BINDIR', $domjudgeRoot . '/bin');
define('ETCDIR', $domjudgeRoot . '/etc');
define('LIBDIR', $domjudgeRoot . '/lib');
define('LIBJUDGEDIR', $domjudgeRoot . '/lib/judge');
define('LOGDIR', sys_get_temp_dir() . '/domjudge-test/log');
define('RUNDIR', sys_get_temp_dir() . '/domjudge-test/run');
define('TMPDIR', sys_get_temp_dir() . '/domjudge-test/tmp');
define('JUDGEDIR', sys_get_temp_dir() . '/domjudge-test/judgings');
define('CHROOTDIR', '/chroot/domjudge');
define('RUNUSER', 'domjudge-run');
define('RUNGROUP', 'domjudge-run');
define('LOGFILE', LOGDIR . '/judge.test.log');

// Create temp directories
@mkdir(LOGDIR, 0755, true);
@mkdir(RUNDIR, 0755, true);
@mkdir(TMPDIR, 0755, true);
@mkdir(JUDGEDIR, 0755, true);

// Include the judgedaemon (DOMJUDGE_TESTING prevents it from running)
require_once $domjudgeRoot . '/judge/judgedaemon.main.php';

// Include lib.error.php for logmsg()
require_once LIBDIR . '/lib.error.php';

// Suppress verbose output during tests
global $verbose;
$verbose = LOG_ERR;
