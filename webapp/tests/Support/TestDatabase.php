<?php declare(strict_types=1);

namespace App\Tests\Support;

use App\DataFixtures\Test\BaselineData;
use App\Utils\Utils;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use FilesystemIterator;
use PDO;
use PDOException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Prepares the database the tests run against, so they can run without setting it up by hand.
 *
 * The baseline (see BaselineData) is built once with webapp/tests/setup-test-database.sh and kept
 * as a dump, which is built again when one of the files it is made from changes. Every run then
 * starts by restoring the dump into its own database: '<name>_test', or '<name>_test<N>' for
 * worker N of ParaTest. Restoring takes about a second and guarantees that a run never sees what
 * an earlier run left behind. The times of the demo contest are moved to the start of the run,
 * like a fresh installation would have them.
 *
 * Set DOMJUDGE_TEST_DATABASE=keep to use the test database as it is. Either way, the run stops
 * with an explanation if the database does not match the baseline.
 */
final class TestDatabase
{
    private const KEEP_VARIABLE = 'DOMJUDGE_TEST_DATABASE';

    /** The files the baseline is built from, relative to the root of the repository. */
    private const SOURCES = [
        'example_problems',
        'etc/db-config.yaml',
        'lib/dj_utils.py.in',
        'misc-tools/import-contest.in',
        'sql/dj_setup_database.in',
        'sql/files/defaultdata',
        'webapp/composer.lock',
        'webapp/migrations',
        'webapp/src/Command/LoadDefaultDataCommand.php',
        'webapp/src/Command/LoadExampleDataCommand.php',
        'webapp/src/DataFixtures/DefaultData',
        'webapp/src/DataFixtures/ExampleData',
        'webapp/src/Entity',
        'webapp/src/Service/ImportExportService.php',
        'webapp/src/Service/ImportProblemService.php',
        'webapp/tests/setup-test-database.sh',
        'webapp/tests/Support/TestDatabase.php',
    ];

    /** Files in the sources that the build itself generates. */
    private const GENERATED = '#^example_problems/(contest\.yaml|[^/]+\.zip)$#';

    private readonly string $root;
    private readonly string $stateDir;
    /** The connection of the tests, from DATABASE_URL. */
    private readonly string $host;
    private readonly ?int $port;
    private readonly string $user;
    private readonly string $password;
    /** The test database, before the number of a ParaTest worker is added. */
    private readonly string $testDatabase;
    private readonly string $database;
    /** The database the baseline is built in, from etc/dbpasswords.secret like dj_setup_database. */
    private readonly string $adminHost;
    private readonly string $adminPort;
    private readonly string $buildDatabase;

    public function __construct(private readonly string $webappDir, string $databaseUrl, string $passwordFile)
    {
        $this->root = dirname($webappDir);
        $this->stateDir = $webappDir . '/var/test-database';

        $url = parse_url($databaseUrl);
        if ($url === false || !isset($url['host'], $url['user'], $url['path'])) {
            throw new RuntimeException('Cannot parse the database URL of the tests.');
        }
        $this->host = rawurldecode($url['host']);
        $this->port = $url['port'] ?? null;
        $this->user = rawurldecode($url['user']);
        $this->password = rawurldecode($url['pass'] ?? '');
        // These are the suffixes the test environment adds, see config/packages/doctrine.yaml.
        $this->testDatabase = rawurldecode(ltrim($url['path'], '/')) . '_test';
        $this->database = $this->testDatabase . ($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '');

        // Like dj_setup_database, use the last line that is not a comment.
        $lines = array_filter(
            file($passwordFile, FILE_IGNORE_NEW_LINES) ?: [],
            static fn(string $line) => trim($line) !== '' && !str_starts_with($line, '#')
        );
        [, $host, $database, , , $port] = array_pad(explode(':', (string)end($lines)), 6, '');
        $this->adminHost = $host;
        $this->adminPort = $port !== '' ? $port : '3306';
        $this->buildDatabase = $database . '_test';
    }

    /**
     * Prepare the database for this test process, or stop the run with an explanation.
     */
    public function prepare(): void
    {
        $restore = ($_SERVER[self::KEEP_VARIABLE] ?? getenv(self::KEEP_VARIABLE)) !== 'keep';
        if ($restore) {
            if ($this->testDatabase !== $this->buildDatabase || $this->host !== $this->adminHost) {
                $this->stop(sprintf(
                    "The tests use the database '%s' on '%s', but the test baseline is built in '%s' on '%s' "
                    . "as configured in etc/dbpasswords.secret. Remove the override of DATABASE_URL, "
                    . "or set %s=keep to use the test database as it is.",
                    $this->testDatabase, $this->host, $this->buildDatabase, $this->adminHost, self::KEEP_VARIABLE
                ));
            }
            [$status, $output] = $this->run([...$this->client('mysql'), '-e', 'SELECT 1']);
            if ($status !== 0) {
                $this->stop(sprintf(
                    "Cannot connect to the database server on '%s' with the 'mysql' client as the current user:\n%s\n"
                    . "The tests use it to restore their database, so it needs to be allowed to create databases, "
                    . "for example by setting the user and password in ~/.my.cnf. "
                    . "Or set %s=keep to use the test database as it is.",
                    $this->adminHost, trim($output), self::KEEP_VARIABLE
                ));
            }
            $this->restore($this->baselineDump());
            $this->ensureAccess();
        }

        try {
            $deviations = $this->withKernel(static function ($em) use ($restore): array {
                if ($restore) {
                    // A fresh installation starts the demo contest when it is installed. Use whole
                    // seconds like the installation does: the start time is stored without fractions,
                    // so a fraction would activate the contest after its start.
                    $now = Utils::absTime(floor(Utils::now()), true);
                    BaselineData::contest($em)->setActivatetimeString($now)->setStarttimeString($now);
                    $em->flush();
                    $em->clear();
                }
                return BaselineData::deviations($em);
            });
        } catch (Throwable $e) {
            $this->stop(sprintf("Cannot check the test database '%s':\n%s", $this->database, $e->getMessage()));
        }
        if ($deviations !== []) {
            $this->stop(sprintf(
                "The test database '%s' does not match the baseline the tests rely on:\n - %s\n"
                . "Unset %s to let the tests restore it, or see %s.",
                $this->database,
                implode("\n - ", $deviations),
                self::KEEP_VARIABLE,
                BaselineData::class
            ));
        }
    }

    /**
     * The dump of the baseline for the current sources, built first if there is none.
     */
    private function baselineDump(): string
    {
        $fingerprint = $this->fingerprint();
        $dump = sprintf('%s/baseline-%s.sql', $this->stateDir, $fingerprint);
        if (is_file($dump)) {
            return $dump;
        }

        if (!is_dir($this->stateDir) && !mkdir($this->stateDir, 0775, true) && !is_dir($this->stateDir)) {
            $this->stop("Cannot create '$this->stateDir'.");
        }
        // Several ParaTest workers may get here at once, let one of them build.
        $lock = fopen($this->stateDir . '/build.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            $this->stop("Cannot lock '$this->stateDir/build.lock'.");
        }
        try {
            if (is_file($dump)) {
                return $dump;
            }

            fwrite(STDERR, "Building the test baseline in '$this->buildDatabase', this takes a while...\n");
            $log = $this->stateDir . '/build.log';
            $env = ['APP_ENV' => 'test', 'TEST_TOKEN' => false];
            [$status] = $this->run([$this->webappDir . '/tests/setup-test-database.sh', '-f'], env: $env, outputFile: $log, mergeErrors: true);
            if ($status !== 0) {
                $this->stop("Building the test baseline failed, see $log.");
            }

            [$status, $output] = $this->run(
                [...$this->client('mysqldump'), '--single-transaction', '--routines', '--triggers', '--events', $this->buildDatabase],
                outputFile: $dump . '.tmp'
            );
            if ($status !== 0) {
                $this->stop("Dumping the test baseline failed:\n$output");
            }
            rename($dump . '.tmp', $dump);

            foreach (glob($this->stateDir . '/baseline-*.sql') ?: [] as $oldDump) {
                if ($oldDump !== $dump) {
                    unlink($oldDump);
                }
            }

            return $dump;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * A hash of the files the baseline is built from.
     */
    private function fingerprint(): string
    {
        $files = [];
        foreach (self::SOURCES as $source) {
            $path = $this->root . '/' . $source;
            if (is_file($path)) {
                $files[] = $source;
            } elseif (is_dir($path)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
                /** @var SplFileInfo $file */
                foreach ($iterator as $file) {
                    $relative = substr($file->getPathname(), strlen($this->root) + 1);
                    if (!preg_match(self::GENERATED, $relative)) {
                        $files[] = $relative;
                    }
                }
            }
        }
        sort($files);

        $hash = hash_init('xxh128');
        foreach ($files as $file) {
            hash_update($hash, $file . "\0");
            hash_update_file($hash, $this->root . '/' . $file);
        }
        return hash_final($hash);
    }

    private function restore(string $dump): void
    {
        [$status, $output] = $this->run([...$this->client('mysql'), '-e', sprintf(
            'DROP DATABASE IF EXISTS `%1$s`; CREATE DATABASE `%1$s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
            $this->database
        )]);
        if ($status !== 0) {
            $this->stop(sprintf(
                "Cannot create the test database '%s':\n%s\nThe tests create it with the 'mysql' client as the current user, "
                . "which needs to be allowed to create databases, for example by setting the user and password in ~/.my.cnf.",
                $this->database,
                $output
            ));
        }

        [$status, $output] = $this->run([...$this->client('mysql'), $this->database], inputFile: $dump);
        if ($status !== 0) {
            $this->stop("Restoring the test baseline into '$this->database' failed:\n$output");
        }
    }

    /**
     * Make sure the application user can use the database: try to grant it, check, or explain.
     */
    private function ensureAccess(): void
    {
        $grant = sprintf("GRANT SELECT, INSERT, UPDATE, DELETE ON `%s`.* TO '%s'@'%%';", $this->database, $this->user);
        // This fails when the current user is not allowed to grant privileges, which is fine if
        // the application user already has access, for example through a wildcard.
        $this->run([...$this->client('mysql'), '-e', $grant]);
        if ($this->connect($this->database) !== null) {
            return;
        }

        $this->stop(sprintf(
            "The database user '%s' cannot use '%s', and you are not allowed to grant it access.\n"
            . "Run the following as a database user that is allowed to grant privileges:\n  %s\n"
            . "To cover the databases of all ParaTest workers at once, grant it on `%s%%` instead. "
            . "If the user also exists as '%s'@'localhost', grant it the same there.",
            $this->user,
            $this->database,
            $grant,
            str_replace('_', '\_', $this->buildDatabase),
            $this->user
        ));
    }

    /**
     * The command for a MySQL client program, connecting to the server like dj_setup_database does.
     *
     * @return string[]
     */
    private function client(string $program): array
    {
        return [$program, '-h', $this->adminHost, '-P', $this->adminPort];
    }

    /**
     * @phpstan-impure the result depends on the privileges in the database server
     */
    private function connect(?string $database): ?PDO
    {
        $dsn = sprintf('mysql:host=%s;%s%s', $this->host, $this->port !== null ? "port=$this->port;" : '',
            $database !== null ? "dbname=$database" : '');
        try {
            return new PDO($dsn, $this->user, $this->password);
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * Run the callback with the entity manager of a kernel of its own, outside of the transaction
     * that DAMA wraps every test in, so changes are kept.
     *
     * @template T
     * @param callable(\Doctrine\Persistence\ObjectManager): T $callback
     * @return T
     */
    private function withKernel(callable $callback): mixed
    {
        $keepStaticConnections = StaticDriver::isKeepStaticConnections();
        StaticDriver::setKeepStaticConnections(false);
        /** @var class-string<KernelInterface> $kernelClass */
        $kernelClass = $_SERVER['KERNEL_CLASS'] ?? $_ENV['KERNEL_CLASS'];
        $kernel = new $kernelClass(
            $_SERVER['APP_ENV'] ?? 'test',
            filter_var($_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
        );
        try {
            $kernel->boot();
            return $callback($kernel->getContainer()->get('doctrine')->getManager());
        } finally {
            $kernel->shutdown();
            StaticDriver::setKeepStaticConnections($keepStaticConnections);
        }
    }

    /**
     * Run a command. Its output goes to $outputFile if given, its errors as well with $mergeErrors.
     *
     * @param string[] $command
     * @param array<string, string|false> $env variables to set, or to remove when false
     * @return array{int, string} the exit status and the output that did not go to $outputFile
     */
    private function run(
        array $command,
        ?string $inputFile = null,
        array $env = [],
        ?string $outputFile = null,
        bool $mergeErrors = false
    ): array {
        $environment = getenv();
        foreach ($env as $name => $value) {
            if ($value === false) {
                unset($environment[$name]);
            } else {
                $environment[$name] = $value;
            }
        }

        // Use files rather than pipes, so a command that writes a lot cannot block on a full pipe.
        $stdin = $inputFile !== null ? fopen($inputFile, 'r') : fopen('/dev/null', 'r');
        $stdout = $outputFile !== null ? fopen($outputFile, 'w') : tmpfile();
        $stderr = $mergeErrors ? $stdout : tmpfile();
        if ($stdin === false || $stdout === false || $stderr === false) {
            $this->stop('Cannot open the files to run ' . $command[0] . ' with.');
        }

        $process = proc_open($command, [0 => $stdin, 1 => $stdout, 2 => $stderr], $pipes, $this->root, $environment);
        if ($process === false) {
            $this->stop('Cannot run ' . $command[0] . '.');
        }
        $status = proc_close($process);

        $output = '';
        if ($outputFile === null) {
            rewind($stdout);
            $output .= stream_get_contents($stdout);
        }
        if (!$mergeErrors) {
            rewind($stderr);
            $output .= stream_get_contents($stderr);
            fclose($stderr);
        }
        fclose($stdin);
        fclose($stdout);

        return [$status, $output];
    }

    private function stop(string $message): never
    {
        fwrite(STDERR, "\n" . $message . "\n");
        exit(1);
    }
}
