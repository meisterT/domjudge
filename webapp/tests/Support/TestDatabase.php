<?php declare(strict_types=1);

namespace App\Tests\Support;

use App\DataFixtures\Test\BaselineData;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Symfony\Component\HttpKernel\KernelInterface;
use Throwable;

/**
 * Checks the database the tests run against before they run, so the tests do not fail one by one
 * when it does not have the data they rely on (see BaselineData).
 *
 * Create that data with webapp/tests/setup-test-database.sh.
 */
final class TestDatabase
{
    /**
     * Check the database for this test process, or stop the run with an explanation.
     */
    public function prepare(): void
    {
        try {
            $deviations = $this->withKernel(static fn($em): array => BaselineData::deviations($em));
        } catch (Throwable $e) {
            $this->stop(sprintf("Cannot check the test database:\n%s", $e->getMessage()));
        }
        if ($deviations !== []) {
            $this->stop(sprintf(
                "The test database does not match the baseline the tests rely on:\n - %s\n"
                . "Set it up with webapp/tests/setup-test-database.sh -f, or see %s.",
                implode("\n - ", $deviations),
                BaselineData::class
            ));
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

    private function stop(string $message): never
    {
        fwrite(STDERR, "\n" . $message . "\n");
        exit(1);
    }
}
