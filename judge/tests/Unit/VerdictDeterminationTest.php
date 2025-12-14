<?php declare(strict_types=1);

namespace DOMjudge\Tests\Unit;

use DOMjudge\JudgeDaemon;
use DOMjudge\Verdict;
use DOMjudge\VerdictInput;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class VerdictDeterminationTest extends TestCase
{
    private ?JudgeDaemon $daemon = null;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(JudgeDaemon::class);
        $this->daemon = $reflection->newInstanceWithoutConstructor();
    }

    private function makeInput(
        array $programMeta = [],
        array $compareMeta = [],
        int $compareExitcode = 42,
        bool $combinedRunCompare = false,
        int $programOutSize = 100,
        bool $compareTimedOut = false,
    ): VerdictInput {
        $defaultProgramMeta = [
            'cpu-time' => '0.1',
            'wall-time' => '0.2',
            'memory-bytes' => '1048576',
            'exitcode' => '0',
            'time-result' => 'pass',
        ];
        $defaultCompareMeta = [
            'exitcode' => (string)$compareExitcode,
        ];

        return new VerdictInput(
            programMeta: array_merge($defaultProgramMeta, $programMeta),
            compareMeta: array_merge($defaultCompareMeta, $compareMeta),
            compareExitcode: $compareExitcode,
            combinedRunCompare: $combinedRunCompare,
            programOutSize: $programOutSize,
            compareTimedOut: $compareTimedOut,
        );
    }

    public function testCorrectVerdict(): void
    {
        $input = $this->makeInput(compareExitcode: 42);
        $this->assertEquals(Verdict::CORRECT, $this->daemon->determineVerdict($input));
    }

    public function testWrongAnswerVerdict(): void
    {
        $input = $this->makeInput(compareExitcode: 43);
        $this->assertEquals(Verdict::WRONG_ANSWER, $this->daemon->determineVerdict($input));
    }

    public function testTimelimitVerdict(): void
    {
        $input = $this->makeInput(
            programMeta: ['time-result' => 'timelimit (hard)', 'exitcode' => '137'],
            compareExitcode: 43,
        );
        $this->assertEquals(Verdict::TIMELIMIT, $this->daemon->determineVerdict($input));
    }

    public function testRunErrorVerdict(): void
    {
        $input = $this->makeInput(
            programMeta: ['exitcode' => '1'],
            compareExitcode: 43,
        );
        $this->assertEquals(Verdict::RUN_ERROR, $this->daemon->determineVerdict($input));
    }

    public function testOutputLimitVerdict(): void
    {
        $input = $this->makeInput(
            programMeta: ['output-truncated' => 'stdout', 'stdout-bytes' => '67108864'],
            compareExitcode: 43,
        );
        $this->assertEquals(Verdict::OUTPUT_LIMIT, $this->daemon->determineVerdict($input));
    }

    public function testNoOutputVerdict(): void
    {
        $input = $this->makeInput(
            compareExitcode: 43,
            programOutSize: 0,
        );
        $this->assertEquals(Verdict::NO_OUTPUT, $this->daemon->determineVerdict($input));
    }

    public function testCompareErrorInvalidExitCode(): void
    {
        $input = $this->makeInput(compareExitcode: 1);
        $this->assertEquals(Verdict::COMPARE_ERROR, $this->daemon->determineVerdict($input));
    }

    public function testCompareErrorTimeout(): void
    {
        $input = $this->makeInput(compareTimedOut: true);
        $this->assertEquals(Verdict::COMPARE_ERROR, $this->daemon->determineVerdict($input));
    }

    public function testInteractiveValidatorExitsFirstOverridesTimelimit(): void
    {
        $input = $this->makeInput(
            programMeta: ['time-result' => 'timelimit (hard)', 'exitcode' => '137'],
            compareMeta: ['validator-exited-first' => 'true', 'exitcode' => '43'],
            compareExitcode: 43,
            combinedRunCompare: true,
        );
        $this->assertEquals(Verdict::WRONG_ANSWER, $this->daemon->determineVerdict($input));
    }

    public function testInteractiveValidatorExitsFirstOverridesRunError(): void
    {
        $input = $this->makeInput(
            programMeta: ['exitcode' => '1'],
            compareMeta: ['validator-exited-first' => 'true', 'exitcode' => '43'],
            compareExitcode: 43,
            combinedRunCompare: true,
        );
        $this->assertEquals(Verdict::WRONG_ANSWER, $this->daemon->determineVerdict($input));
    }

    public function testInteractiveValidatorExitsFirstDoesNotOverrideWhenCorrect(): void
    {
        $input = $this->makeInput(
            programMeta: ['time-result' => 'timelimit (hard)', 'exitcode' => '137'],
            compareMeta: ['validator-exited-first' => 'true', 'exitcode' => '42'],
            compareExitcode: 42,
            combinedRunCompare: true,
        );
        $this->assertEquals(Verdict::TIMELIMIT, $this->daemon->determineVerdict($input));
    }

    public function testNonInteractiveIgnoresValidatorExitedFirst(): void
    {
        $input = $this->makeInput(
            programMeta: ['time-result' => 'timelimit (hard)', 'exitcode' => '137'],
            compareMeta: ['validator-exited-first' => 'true', 'exitcode' => '43'],
            compareExitcode: 43,
            combinedRunCompare: false,
        );
        $this->assertEquals(Verdict::TIMELIMIT, $this->daemon->determineVerdict($input));
    }

    public function testCompareTimeoutPriority(): void
    {
        $input = $this->makeInput(
            programMeta: ['exitcode' => '0'],
            compareExitcode: 42,
            compareTimedOut: true,
        );
        $this->assertEquals(Verdict::COMPARE_ERROR, $this->daemon->determineVerdict($input));
    }

    public function testTimelimitPriorityOverRunError(): void
    {
        $input = $this->makeInput(
            programMeta: ['time-result' => 'timelimit', 'exitcode' => '139'],
            compareExitcode: 43,
        );
        $this->assertEquals(Verdict::TIMELIMIT, $this->daemon->determineVerdict($input));
    }

    public function testRunErrorPriorityOverOutputLimit(): void
    {
        $input = $this->makeInput(
            programMeta: ['exitcode' => '1', 'output-truncated' => 'stdout'],
            compareExitcode: 43,
        );
        $this->assertEquals(Verdict::RUN_ERROR, $this->daemon->determineVerdict($input));
    }

    public function testStderrOnlyTruncationDoesNotTriggerOutputLimit(): void
    {
        $input = $this->makeInput(
            programMeta: ['output-truncated' => 'stderr', 'stderr-bytes' => '67108864'],
            compareExitcode: 43,
        );
        $this->assertEquals(Verdict::WRONG_ANSWER, $this->daemon->determineVerdict($input));
    }

    public function testInteractiveValidatorExitedFirstFalse(): void
    {
        $input = $this->makeInput(
            programMeta: ['time-result' => 'timelimit (hard)', 'exitcode' => '137'],
            compareMeta: ['validator-exited-first' => 'false', 'exitcode' => '43'],
            compareExitcode: 43,
            combinedRunCompare: true,
        );
        $this->assertEquals(Verdict::TIMELIMIT, $this->daemon->determineVerdict($input));
    }

    public function testNoOutputInteractiveMode(): void
    {
        $input = $this->makeInput(
            compareExitcode: 43,
            combinedRunCompare: true,
            programOutSize: 0,
        );
        $this->assertEquals(Verdict::WRONG_ANSWER, $this->daemon->determineVerdict($input));
    }
}
