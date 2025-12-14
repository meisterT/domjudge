<?php declare(strict_types=1);

namespace DOMjudge\Tests\Unit;

use DOMjudge\JudgeDaemon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for the getWrongAnswerMessage method in JudgeDaemon.
 *
 * This method generates appropriate wrong answer messages including
 * override information for interactive problems.
 */
class WrongAnswerMessageTest extends TestCase
{
    private ?JudgeDaemon $daemon = null;
    private ?ReflectionMethod $method = null;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(JudgeDaemon::class);
        $this->daemon = $reflection->newInstanceWithoutConstructor();
        $this->method = $reflection->getMethod('getWrongAnswerMessage');
        $this->method->setAccessible(true);
    }

    private function callGetWrongAnswerMessage(
        array $programMeta,
        array $compareMeta,
        bool $combinedRunCompare
    ): string {
        return $this->method->invoke($this->daemon, $programMeta, $compareMeta, $combinedRunCompare);
    }

    // ==================== Basic Wrong Answer Messages ====================

    public function testBasicWrongAnswer(): void
    {
        $result = $this->callGetWrongAnswerMessage(
            programMeta: ['exitcode' => '0'],
            compareMeta: ['exitcode' => '43'],
            combinedRunCompare: false
        );
        $this->assertEquals('Wrong answer!', $result);
    }

    public function testNonInteractiveIgnoresValidatorExitedFirst(): void
    {
        $result = $this->callGetWrongAnswerMessage(
            programMeta: ['time-result' => 'timelimit (hard)', 'exitcode' => '137'],
            compareMeta: ['validator-exited-first' => 'true', 'exitcode' => '43'],
            combinedRunCompare: false
        );
        // Non-interactive mode should just return basic message
        $this->assertEquals('Wrong answer!', $result);
    }

    // ==================== Interactive Mode Override Messages ====================

    public function testInteractiveTimelimitOverride(): void
    {
        $result = $this->callGetWrongAnswerMessage(
            programMeta: ['time-result' => 'timelimit (hard)', 'exitcode' => '137'],
            compareMeta: ['validator-exited-first' => 'true', 'exitcode' => '43'],
            combinedRunCompare: true
        );
        $this->assertEquals(
            'Timelimit exceeded, but validator exited first with WA.Wrong answer!',
            $result
        );
    }

    public function testInteractiveSoftTimelimitOverride(): void
    {
        $result = $this->callGetWrongAnswerMessage(
            programMeta: ['time-result' => 'timelimit (soft)', 'exitcode' => '0'],
            compareMeta: ['validator-exited-first' => 'true', 'exitcode' => '43'],
            combinedRunCompare: true
        );
        $this->assertEquals(
            'Timelimit exceeded, but validator exited first with WA.Wrong answer!',
            $result
        );
    }

    public function testInteractiveRunErrorOverride(): void
    {
        $result = $this->callGetWrongAnswerMessage(
            programMeta: ['exitcode' => '1'],
            compareMeta: ['validator-exited-first' => 'true', 'exitcode' => '43'],
            combinedRunCompare: true
        );
        $this->assertEquals(
            'Non-zero exitcode 1, but validator exited first with WA.Wrong answer!',
            $result
        );
    }

    public function testInteractiveSegfaultOverride(): void
    {
        $result = $this->callGetWrongAnswerMessage(
            programMeta: ['exitcode' => '139'],
            compareMeta: ['validator-exited-first' => 'true', 'exitcode' => '43'],
            combinedRunCompare: true
        );
        $this->assertEquals(
            'Non-zero exitcode 139, but validator exited first with WA.Wrong answer!',
            $result
        );
    }

    // ==================== Interactive Mode Without Override ====================

    public function testInteractiveNoOverrideWhenValidatorDidNotExitFirst(): void
    {
        $result = $this->callGetWrongAnswerMessage(
            programMeta: ['time-result' => 'timelimit (hard)', 'exitcode' => '137'],
            compareMeta: ['validator-exited-first' => 'false', 'exitcode' => '43'],
            combinedRunCompare: true
        );
        $this->assertEquals('Wrong answer!', $result);
    }

    public function testInteractiveNoOverrideWhenValidatorExitedFirstMissing(): void
    {
        $result = $this->callGetWrongAnswerMessage(
            programMeta: ['time-result' => 'timelimit (hard)', 'exitcode' => '137'],
            compareMeta: ['exitcode' => '43'],
            combinedRunCompare: true
        );
        $this->assertEquals('Wrong answer!', $result);
    }

    public function testInteractiveNoOverrideWhenProgramSucceeded(): void
    {
        $result = $this->callGetWrongAnswerMessage(
            programMeta: ['exitcode' => '0'],
            compareMeta: ['validator-exited-first' => 'true', 'exitcode' => '43'],
            combinedRunCompare: true
        );
        // No override message when program had normal exit
        $this->assertEquals('Wrong answer!', $result);
    }

    // ==================== Edge Cases ====================

    public function testEmptyProgramMeta(): void
    {
        $result = $this->callGetWrongAnswerMessage(
            programMeta: [],
            compareMeta: ['validator-exited-first' => 'true', 'exitcode' => '43'],
            combinedRunCompare: true
        );
        $this->assertEquals('Wrong answer!', $result);
    }

    public function testEmptyCompareMeta(): void
    {
        $result = $this->callGetWrongAnswerMessage(
            programMeta: ['exitcode' => '1'],
            compareMeta: [],
            combinedRunCompare: true
        );
        $this->assertEquals('Wrong answer!', $result);
    }
}
