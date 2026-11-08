<?php declare(strict_types=1);

namespace DOMjudge\Tests\Unit;

use DOMjudge\ExitRequest;
use DOMjudge\JudgeDaemon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

class SignalHandlingTest extends TestCase
{
    private JudgeDaemon $daemon;
    private ReflectionProperty $exitRequest;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(JudgeDaemon::class);
        $this->daemon = $reflection->newInstanceWithoutConstructor();
        $this->exitRequest = $reflection->getProperty('exitRequest');
    }

    /**
     * @return iterable<string, array{list<int>, ExitRequest}>
     */
    public static function provideSignals(): iterable
    {
        yield 'SIGHUP finishes the judging' => [[SIGHUP], ExitRequest::GRACEFUL];
        yield 'SIGINT finishes the judging' => [[SIGINT], ExitRequest::GRACEFUL];
        yield 'SIGTERM finishes the judging' => [[SIGTERM], ExitRequest::GRACEFUL];
        yield 'SIGQUIT aborts the judging' => [[SIGQUIT], ExitRequest::ABORT];
        yield 'SIGQUIT escalates a graceful exit' => [[SIGTERM, SIGQUIT], ExitRequest::ABORT];
        yield 'SIGTERM does not weaken an abort' => [[SIGQUIT, SIGTERM], ExitRequest::ABORT];
        yield 'other signals are ignored' => [[SIGUSR2], ExitRequest::NONE];
    }

    /**
     * @dataProvider provideSignals
     * @param list<int> $signals
     */
    public function testSignalSetsExitRequest(array $signals, ExitRequest $expected): void
    {
        foreach ($signals as $signal) {
            $this->daemon->handleSignal($signal);
        }
        self::assertSame($expected, $this->exitRequest->getValue($this->daemon));
    }
}
