<?php declare(strict_types=1);

namespace DOMjudge\Tests\Unit;

use DOMjudge\JudgeDaemon;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DisableValidatorTest extends TestCase
{
    public function testDisablesExpectedScriptForValidatorMode(): void
    {
        $GLOBALS['verbose'] = LOG_CRIT;
        $daemon = new class extends JudgeDaemon {
            /** @var list<array<int, mixed>> */
            public array $calls = [];

            public function __construct()
            {
            }

            protected function disable(string $kind, string $idcolumn, mixed $id, string $description, ?int $judgeTaskId = null, ?string $extra_log = null): void
            {
                $this->calls[] = [$kind, $idcolumn, $id, $description, $judgeTaskId];
            }
        };

        $method = new ReflectionMethod(JudgeDaemon::class, 'disableValidator');
        $task = [
            'judgetaskid' => 77,
            'run_script_id' => 'interactive_val_cmp',
            'compare_script_id' => 'default_compare',
        ];

        $method->invoke($daemon, false, $task, 'produced more passes than allowed (2)');
        $method->invoke($daemon, true, $task, "created 'nextpass.in' but exited with code 43, expected 42");

        $this->assertSame([
            ['compare_script', 'compare_script_id', 'default_compare', 'compare script default_compare produced more passes than allowed (2)', 77],
            ['run_script', 'run_script_id', 'interactive_val_cmp', "combined run/compare script interactive_val_cmp created 'nextpass.in' but exited with code 43, expected 42", 77],
        ], $daemon->calls);
    }
}
