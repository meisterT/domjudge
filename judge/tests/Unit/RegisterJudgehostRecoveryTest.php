<?php declare(strict_types=1);

namespace DOMjudge\Tests\Unit;

use DOMjudge\JudgeDaemon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class TestableRecoveryJudgeDaemon extends JudgeDaemon
{
    /** @var list<string|null> */
    public array $queuedRegisterResponses = [];
    public int $registerRequestCount = 0;

    /** @phpstan-impure */
    public function getRegisterRequestCount(): int
    {
        return $this->registerRequestCount;
    }

    protected function request(
        string $url,
        string $verb = 'GET',
        string|array $data = '',
        bool $failonerror = true
    ): string|bool|null {
        if ($url === 'judgehosts' && $verb === 'POST') {
            $this->registerRequestCount++;
            return array_shift($this->queuedRegisterResponses);
        }
        return '[]';
    }
}

class RegisterJudgehostRecoveryTest extends TestCase
{
    private TestableRecoveryJudgeDaemon $daemon;
    /** @var ReflectionClass<JudgeDaemon> */
    private ReflectionClass $baseReflection;
    private ReflectionMethod $registerJudgehost;
    private ReflectionMethod $reapChildren;
    private ReflectionProperty $endpointProp;
    private string $hostName;
    private int $previousVerbose;

    protected function setUp(): void
    {
        global $verbose;
        $this->previousVerbose = $verbose;
        $verbose = LOG_CRIT;

        $subReflection = new ReflectionClass(TestableRecoveryJudgeDaemon::class);
        $this->daemon = $subReflection->newInstanceWithoutConstructor();
        $this->daemon->queuedRegisterResponses = [];
        $this->daemon->registerRequestCount = 0;

        $this->baseReflection = new ReflectionClass(JudgeDaemon::class);
        $this->registerJudgehost = $this->baseReflection->getMethod('registerJudgehost');
        $this->reapChildren = $this->baseReflection->getMethod('reapChildren');
        $this->endpointProp = $this->baseReflection->getProperty('endpoint');

        $this->hostName = 'recovery-host-' . uniqid();
        $this->baseReflection->getProperty('myhost')->setValue($this->daemon, $this->hostName);
        $this->endpointProp->setValue($this->daemon, [
            'id' => 'default',
            'url' => 'http://localhost/api',
            'user' => 'judgehost',
            'pass' => 'secret',
            'waiting' => false,
            'errorred' => false,
            'retrying' => false,
            'last_attempt' => -1,
        ]);
    }

    protected function tearDown(): void
    {
        global $verbose;
        $verbose = $this->previousVerbose;

        $hostDir = JUDGEDIR . '/' . $this->hostName;
        if (is_dir($hostDir)) {
            exec('rm -rf ' . escapeshellarg($hostDir));
        }
    }

    public function testRegisterJudgehostThrottlesWithin30SecondsUnlessForced(): void
    {
        $workdir = JUDGEDIR . '/' . $this->hostName . '/endpoint-default/10/20';
        mkdir($workdir, 0755, true);
        chmod($workdir, 0755);

        // Initial registration succeeds.
        $this->daemon->queuedRegisterResponses[] = '[]';
        $this->registerJudgehost->invoke($this->daemon);
        self::assertSame(1, $this->daemon->getRegisterRequestCount());

        // Immediate unforced retry within 30s is throttled and marks endpoint as waiting.
        $this->registerJudgehost->invoke($this->daemon);
        self::assertSame(1, $this->daemon->getRegisterRequestCount());
        /** @var array{waiting: bool, last_attempt: int} $endpoint */
        $endpoint = $this->endpointProp->getValue($this->daemon);
        self::assertTrue($endpoint['waiting']);

        // Forced registration (used after failed fetch-work or failed async reportJudgingRun)
        // bypasses the 30s throttle and immediately returns unfinished judgings.
        $this->daemon->queuedRegisterResponses[] = json_encode([
            ['jobid' => 20, 'submitid' => '10'],
        ], JSON_THROW_ON_ERROR);
        $this->registerJudgehost->invoke($this->daemon, true);
        self::assertSame(2, $this->daemon->getRegisterRequestCount());
        clearstatcache(true, $workdir);
        self::assertSame(0700, fileperms($workdir) & 0777);

        // Subsequent unforced call is still throttled so an unreachable server is not hammered.
        $this->registerJudgehost->invoke($this->daemon, false);
        self::assertSame(2, $this->daemon->getRegisterRequestCount());
    }

    public function testReapChildrenReturnsFalseWhenChildrenSucceed(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_exec')) {
            self::markTestSkipped('pcntl extension required');
        }

        // No children running -> false.
        self::assertFalse($this->reapChildren->invoke($this->daemon));

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            pcntl_exec('/bin/sh', ['-c', 'exit 0']);
            exit(0);
        }

        // Wait until the child has exited, then let reapChildren() reap it via WNOHANG.
        usleep(50000);
        self::assertFalse($this->reapChildren->invoke($this->daemon));
    }

    public function testReapChildrenDetectsFailedChildAndWaitsForSiblings(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_exec')) {
            self::markTestSkipped('pcntl extension required');
        }

        $markerFile = JUDGEDIR . '/' . $this->hostName . '-sibling.done';

        // Child 1 fails immediately.
        $failedPid = pcntl_fork();
        self::assertNotSame(-1, $failedPid);
        if ($failedPid === 0) {
            pcntl_exec('/bin/sh', ['-c', 'exit 1']);
            exit(1);
        }

        // Child 2 takes slightly longer and creates a marker file before exiting 0.
        $siblingPid = pcntl_fork();
        self::assertNotSame(-1, $siblingPid);
        if ($siblingPid === 0) {
            pcntl_exec('/bin/sh', ['-c', 'sleep 0.1 && touch ' . escapeshellarg($markerFile)]);
            exit(0);
        }

        // Give Child 1 time to fail while Child 2 is still running.
        usleep(40000);
        self::assertTrue($this->reapChildren->invoke($this->daemon));
        self::assertFileExists($markerFile, 'reapChildren must wait for in-flight sibling children before returning');
        @unlink($markerFile);
    }
}
