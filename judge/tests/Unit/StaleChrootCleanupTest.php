<?php declare(strict_types=1);

namespace DOMjudge\Tests\Unit;

use DOMjudge\JudgeDaemon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class StaleChrootCleanupTest extends TestCase
{
    private JudgeDaemon $daemon;
    /** @var ReflectionClass<JudgeDaemon> */
    private ReflectionClass $reflection;
    private ReflectionMethod $findMountedWorkdirs;
    private ReflectionMethod $hasMountsUnder;
    private ReflectionMethod $maybeCleanupJudging;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->reflection = new ReflectionClass(JudgeDaemon::class);
        $this->daemon = $this->reflection->newInstanceWithoutConstructor();
        $this->findMountedWorkdirs = $this->reflection->getMethod('findMountedWorkdirs');
        $this->hasMountsUnder = $this->reflection->getMethod('hasMountsUnder');
        $this->maybeCleanupJudging = $this->reflection->getMethod('maybeCleanupJudging');

        $this->tempDir = sys_get_temp_dir() . '/domjudge-chroot-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    /**
     * @return list<string>
     */
    private function findMountedWorkdirs(string $workdirpath, string $mountinfoPath): array
    {
        return $this->findMountedWorkdirs->invoke($this->daemon, $workdirpath, $mountinfoPath);
    }

    private function hasMountsUnder(string $dir, string $mountinfoPath): bool
    {
        return $this->hasMountsUnder->invoke($this->daemon, $dir, $mountinfoPath);
    }

    private function escapeMountinfoPath(string $path): string
    {
        return strtr($path, [
            '\\' => '\\134',
            ' ' => '\\040',
            "\t" => '\\011',
            "\n" => '\\012',
        ]);
    }

    /**
     * @param list<string> $mountPoints
     */
    private function writeMountinfo(array $mountPoints): string
    {
        $lines = [];
        foreach ($mountPoints as $idx => $mountPoint) {
            $id = 100 + $idx;
            $escaped = $this->escapeMountinfoPath($mountPoint);
            $lines[] = "$id 33 0:24 / $escaped rw,relatime shared:1 - proc proc rw";
        }
        $mountinfo = $this->tempDir . '/mountinfo';
        file_put_contents($mountinfo, implode("\n", $lines) . "\n");
        return $mountinfo;
    }

    public function testFindsActiveAndRenamedWorkdirsAndDeduplicatesMounts(): void
    {
        $workdirpath = $this->tempDir . '/endpoint-default';
        mkdir($workdirpath, 0755, true);
        $realBase = (string)realpath($workdirpath);

        $mountinfo = $this->writeMountinfo([
            '/proc',
            "$realBase/3989/4112/proc",
            "$realBase/3989/4112/usr",
            "$realBase/3989/4112/usr/local",
            "$realBase/sub_ext.1/4113-old-987-2026-11-11_21:00/lib64",
            "$realBase-other/9999/1/proc",
            "$realBase/testcase/42/input",
            "$realBase/executable/compile/c/hash/build",
        ]);

        self::assertSame(
            [
                "$realBase/3989/4112",
                "$realBase/sub_ext.1/4113-old-987-2026-11-11_21:00",
            ],
            $this->findMountedWorkdirs($workdirpath, $mountinfo)
        );
    }

    public function testResolvesSymlinkedWorkdirAndOctalEscapes(): void
    {
        $realRoot = $this->tempDir . '/real dir';
        $workdirpathReal = $realRoot . '/endpoint-default';
        mkdir($workdirpathReal, 0755, true);
        $canonicalBase = (string)realpath($workdirpathReal);

        $symlinkBase = $this->tempDir . '/symlink-endpoint';
        symlink($workdirpathReal, $symlinkBase);

        $mountinfo = $this->writeMountinfo([
            "$canonicalBase/101/202/proc",
        ]);

        self::assertSame(
            ["$canonicalBase/101/202"],
            $this->findMountedWorkdirs($symlinkBase, $mountinfo)
        );
        self::assertTrue($this->hasMountsUnder("$symlinkBase/101/202", $mountinfo));
        self::assertFalse($this->hasMountsUnder("$symlinkBase/101/2020", $mountinfo));
        self::assertFalse($this->hasMountsUnder("$symlinkBase/101/202-old-1", $mountinfo));
    }

    public function testMissingMountinfoReturnsEmpty(): void
    {
        $missing = $this->tempDir . '/does-not-exist';
        self::assertSame([], $this->findMountedWorkdirs($this->tempDir, $missing));
        self::assertFalse($this->hasMountsUnder($this->tempDir, $missing));
    }

    public function testMaybeCleanupJudgingHandlesMissingDirectoryGracefully(): void
    {
        $lastWorkdirProp = $this->reflection->getProperty('lastWorkdir');
        $lastWorkdirProp->setValue($this->daemon, $this->tempDir . '/already-gone');

        $this->maybeCleanupJudging->invoke($this->daemon);

        self::assertNull($lastWorkdirProp->getValue($this->daemon));
    }

    public function testSignalsSetExitFlagsCorrectly(): void
    {
        $exitProp = $this->reflection->getProperty('exitsignalled');
        $gracefulProp = $this->reflection->getProperty('gracefulexitsignalled');

        $this->daemon->handleSignal(SIGUSR1);
        self::assertTrue($exitProp->getValue($this->daemon));
        self::assertTrue($gracefulProp->getValue($this->daemon));

        $freshDaemon = $this->reflection->newInstanceWithoutConstructor();
        $freshDaemon->handleSignal(SIGTERM);
        self::assertTrue($exitProp->getValue($freshDaemon));
        self::assertFalse($gracefulProp->getValue($freshDaemon));
    }
}
