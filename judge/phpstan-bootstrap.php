<?php declare(strict_types=1);
/**
 * PHPStan bootstrap file for judge directory analysis.
 *
 * Defines constants and includes library files so PHPStan can find
 * all symbols used in judgedaemon.main.php.
 */

// Prevent the judgedaemon from running when included
define('DOMJUDGE_TESTING', true);

// Define SCRIPT_ID early so lib.error.php can use it
define('SCRIPT_ID', 'judgedaemon');

$domjudgeRoot = dirname(__DIR__);

// Define required path constants
define('DOMJUDGE_VERSION', 'phpstan');
define('BINDIR', $domjudgeRoot . '/bin');
define('ETCDIR', $domjudgeRoot . '/etc');
define('LIBDIR', $domjudgeRoot . '/lib');
define('LIBJUDGEDIR', $domjudgeRoot . '/lib/judge');

$tempDir = sys_get_temp_dir() . '/domjudge-phpstan';
define('LOGDIR', $tempDir . '/log');
define('RUNDIR', $tempDir . '/run');
define('TMPDIR', $tempDir . '/tmp');
define('JUDGEDIR', $tempDir . '/judgings');
define('CHROOTDIR', '/chroot/domjudge');
define('RUNUSER', 'domjudge-run');
define('RUNGROUP', 'domjudge-run');

// Include judgehost-config.php for BACKOFF_* and CREATE_WRITABLE_TEMP_DIR constants
require_once ETCDIR . '/judgehost-config.php';

// Include library files for function definitions
// lib.misc.php includes lib.wrappers.php
require_once LIBDIR . '/lib.error.php';
require_once LIBDIR . '/lib.misc.php';
