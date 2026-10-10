<?php declare(strict_types=1);

namespace App\Tests\StatementHook;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use SensitiveParameter;

/**
 * Lets a test run code right before the application executes a given statement.
 *
 * Races between two requests do not reproduce single-threaded, but a test can play the other
 * request: register a hook for the statement where the interleaving matters, and have the
 * callback do what the competitor would have done by then. Test environment only, see the
 * when@test block in config/services.yaml.
 */
class StatementHookMiddleware implements Middleware
{
    /** @var list<array{pattern: string, callback: callable(): void}> */
    private static array $hooks = [];

    /**
     * Run $callback once, right before the next statement matching the regular expression
     * $pattern is executed. The callback's own statements do not trigger hooks that are
     * already used up, so it can query freely.
     *
     * @param callable(): void $callback
     */
    public static function once(string $pattern, callable $callback): void
    {
        self::$hooks[] = ['pattern' => $pattern, 'callback' => $callback];
    }

    public static function reset(): void
    {
        self::$hooks = [];
    }

    public static function fire(string $sql): void
    {
        foreach (self::$hooks as $index => $hook) {
            if (preg_match($hook['pattern'], $sql) === 1) {
                // Remove before running, as the callback issues statements itself.
                unset(self::$hooks[$index]);
                ($hook['callback'])();
            }
        }
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        return new class(parent::prepare($sql), $sql) extends AbstractStatementMiddleware {
                            public function __construct(Statement $statement, private readonly string $sql)
                            {
                                parent::__construct($statement);
                            }

                            public function execute(): Result
                            {
                                StatementHookMiddleware::fire($this->sql);

                                return parent::execute();
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        StatementHookMiddleware::fire($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        StatementHookMiddleware::fire($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
