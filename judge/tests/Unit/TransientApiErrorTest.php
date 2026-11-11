<?php declare(strict_types=1);

namespace DOMjudge\Tests\Unit;

use DOMjudge\JudgeDaemon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class TestableApiJudgeDaemon extends JudgeDaemon
{
    /** @var list<array{response: string|bool|null, status: ?int}> */
    public array $queuedResponses = [];

    /** @var list<array{url: string, verb: string, data: array<string, string>|string, failonerror: bool}> */
    public array $recordedRequests = [];

    /** @var list<array{kind: string, idcolumn: string, id: mixed, description: string, judgeTaskId: ?int}> */
    public array $disabledItems = [];

    protected function request(
        string $url,
        string $verb = 'GET',
        string|array $data = '',
        bool $failonerror = true
    ): string|bool|null {
        $this->recordedRequests[] = [
            'url' => $url,
            'verb' => $verb,
            'data' => $data,
            'failonerror' => $failonerror,
        ];
        $next = array_shift($this->queuedResponses) ?? ['response' => null, 'status' => null];
        $this->lastHttpStatus = $next['status'];
        return $next['response'];
    }

    protected function disable(
        string $kind,
        string $idcolumn,
        mixed $id,
        string $description,
        ?int $judgeTaskId = null,
        ?string $extra_log = null
    ): void {
        $this->disabledItems[] = [
            'kind' => $kind,
            'idcolumn' => $idcolumn,
            'id' => $id,
            'description' => $description,
            'judgeTaskId' => $judgeTaskId,
        ];
    }
}

class TransientApiErrorTest extends TestCase
{
    private TestableApiJudgeDaemon $daemon;
    /** @var ReflectionClass<JudgeDaemon> */
    private ReflectionClass $baseReflection;
    private string $tempDir;

    private int $previousVerbose;

    protected function setUp(): void
    {
        global $verbose;
        $this->previousVerbose = $verbose;
        $verbose = LOG_CRIT;

        $subReflection = new ReflectionClass(TestableApiJudgeDaemon::class);
        $this->daemon = $subReflection->newInstanceWithoutConstructor();
        $this->daemon->queuedResponses = [];
        $this->daemon->recordedRequests = [];
        $this->daemon->disabledItems = [];

        $this->baseReflection = new ReflectionClass(JudgeDaemon::class);
        $this->baseReflection->getProperty('myhost')->setValue($this->daemon, 'test-judgehost');

        $this->tempDir = sys_get_temp_dir() . '/domjudge-api-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        global $verbose;
        $verbose = $this->previousVerbose;

        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testDjconfigRefreshHandlesTransientFailureAndPreservesExistingConfig(): void
    {
        $configProp = $this->baseReflection->getProperty('domjudge_config');
        $configProp->setValue($this->daemon, ['diskspace_error' => 1024]);
        $method = $this->baseReflection->getMethod('djconfigRefresh');

        $this->daemon->queuedResponses[] = ['response' => null, 'status' => 503];
        self::assertFalse($method->invoke($this->daemon, false));
        self::assertFalse($this->daemon->recordedRequests[0]['failonerror']);
        self::assertSame(['diskspace_error' => 1024], $configProp->getValue($this->daemon));

        $this->daemon->queuedResponses[] = ['response' => '{"diskspace_error":2048}', 'status' => 200];
        self::assertTrue($method->invoke($this->daemon, false));
        self::assertSame(['diskspace_error' => 2048], $configProp->getValue($this->daemon));
    }

    public function testFetchExecutableDoesNotDisableScriptOnTransientError(): void
    {
        $method = $this->baseReflection->getMethod('fetchExecutable');
        $this->daemon->queuedResponses[] = ['response' => null, 'status' => 502];

        [$runpath, $error] = $method->invoke(
            $this->daemon,
            $this->tempDir,
            'compile',
            'c',
            'deadbeef',
            99
        );

        self::assertNull($runpath);
        self::assertNull($error);
        self::assertSame([], $this->daemon->disabledItems);
        self::assertFalse($this->daemon->recordedRequests[0]['failonerror']);
        self::assertFileDoesNotExist($this->tempDir . '/executable/compile/c/deadbeef/.deployed');
    }

    public function testFetchExecutableDisablesScriptOn404(): void
    {
        $method = $this->baseReflection->getMethod('fetchExecutable');
        $this->daemon->queuedResponses[] = ['response' => null, 'status' => 404];

        [$runpath, $error] = $method->invoke(
            $this->daemon,
            $this->tempDir,
            'compile',
            'c',
            'deadbeef',
            99
        );

        self::assertNull($runpath);
        self::assertNotNull($error);
        self::assertCount(1, $this->daemon->disabledItems);
        self::assertSame('compile_script', $this->daemon->disabledItems[0]['kind']);
        self::assertSame('c', $this->daemon->disabledItems[0]['id']);
    }

    public function testFetchTestcaseDoesNotDisableTestcaseOnTransientError(): void
    {
        $method = $this->baseReflection->getMethod('fetchTestcase');
        $this->daemon->queuedResponses[] = ['response' => null, 'status' => null];

        self::assertNull($method->invoke($this->daemon, $this->tempDir, '42', 100, 'abc123'));
        self::assertSame([], $this->daemon->disabledItems);
    }

    public function testFetchTestcaseDisablesTestcaseOn404(): void
    {
        $method = $this->baseReflection->getMethod('fetchTestcase');
        $this->daemon->queuedResponses[] = ['response' => null, 'status' => 404];

        self::assertNull($method->invoke($this->daemon, $this->tempDir, '42', 100, 'abc123'));
        self::assertCount(1, $this->daemon->disabledItems);
        self::assertSame('testcase', $this->daemon->disabledItems[0]['kind']);
        self::assertSame('42', $this->daemon->disabledItems[0]['id']);
    }

    public function testCompileReturnsFalseWithoutTouchingCompileSuccessOnTransientErrors(): void
    {
        $compileMethod = $this->baseReflection->getMethod('compile');
        $workdir = $this->tempDir . '/1/2';
        mkdir($workdir . '/compile', 0755, true);

        $judgeTask = [
            'judgetaskid' => 77,
            'contestid' => 'demo',
            'submitid' => '1',
            'compile_script_id' => 'c',
        ];
        $compileConfig = [
            'script_timelimit' => 30,
            'script_memory_limit' => 2097152,
            'language_extensions' => ['c'],
            'filter_compiler_files' => true,
            'hash' => 'deadbeef',
        ];

        // 1. Transient failure fetching version commands.
        $this->daemon->queuedResponses = [['response' => null, 'status' => 503]];
        self::assertFalse($compileMethod->invoke($this->daemon, $judgeTask, $workdir, $this->tempDir, $compileConfig, null, 64000));
        self::assertFileDoesNotExist("$workdir/compile.success");

        // 2. Transient failure fetching submission sources.
        $this->daemon->queuedResponses = [
            ['response' => '{}', 'status' => 200],
            ['response' => null, 'status' => 503],
        ];
        self::assertFalse($compileMethod->invoke($this->daemon, $judgeTask, $workdir, $this->tempDir, $compileConfig, null, 64000));
        self::assertFileDoesNotExist("$workdir/compile.success");

        // 3. Transient failure reporting filtered-files compile error via update-judging.
        $sourcesJson = json_encode([['filename' => 'solution.py', 'content' => base64_encode('print(1)')]], JSON_THROW_ON_ERROR);
        $this->daemon->queuedResponses = [
            ['response' => '{}', 'status' => 200],
            ['response' => $sourcesJson, 'status' => 200],
            ['response' => null, 'status' => 503],
        ];
        self::assertFalse($compileMethod->invoke($this->daemon, $judgeTask, $workdir, $this->tempDir, $compileConfig, null, 64000));
        self::assertFileDoesNotExist("$workdir/compile.success");

        foreach ($this->daemon->recordedRequests as $req) {
            self::assertFalse($req['failonerror'], "Expected failonerror=false for {$req['verb']} {$req['url']}");
        }
    }
}
