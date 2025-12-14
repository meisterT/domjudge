<?php declare(strict_types=1);

namespace DOMjudge\Tests\Unit;

use DOMjudge\JudgeDaemon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class ReadMetadataTest extends TestCase
{
    private ?JudgeDaemon $daemon = null;
    private ?ReflectionMethod $readMetadataMethod = null;
    private string $tempDir;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(JudgeDaemon::class);
        $this->daemon = $reflection->newInstanceWithoutConstructor();
        $this->readMetadataMethod = $reflection->getMethod('readMetadata');
        $this->readMetadataMethod->setAccessible(true);
        $this->tempDir = sys_get_temp_dir() . '/domjudge-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                chmod($path, 0644);
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function callReadMetadata(string $filename): ?array
    {
        return $this->readMetadataMethod->invoke($this->daemon, $filename);
    }

    private function writeMetaFile(string $content): string
    {
        $filename = $this->tempDir . '/test.meta';
        file_put_contents($filename, $content);
        return $filename;
    }

    public static function metadataProvider(): array
    {
        return [
            'typical program.meta' => [
                "cpu-time: 0.123\nwall-time: 0.456\nmemory-bytes: 1048576\nexitcode: 0",
                ['cpu-time' => '0.123', 'wall-time' => '0.456', 'memory-bytes' => '1048576', 'exitcode' => '0'],
            ],
            'with timelimit result' => [
                "cpu-time: 5.001\nexitcode: 137\ntime-result: timelimit (hard)",
                ['cpu-time' => '5.001', 'exitcode' => '137', 'time-result' => 'timelimit (hard)'],
            ],
            'with output truncated' => [
                "exitcode: 0\nstdout-bytes: 1048577\noutput-truncated: stdout",
                ['exitcode' => '0', 'stdout-bytes' => '1048577', 'output-truncated' => 'stdout'],
            ],
            'validator-exited-first' => [
                "exitcode: 43\nvalidator-exited-first: true",
                ['exitcode' => '43', 'validator-exited-first' => 'true'],
            ],
            'values with colons preserved' => [
                "message: error: something went wrong: details\ntime: 12:34:56",
                ['message' => 'error: something went wrong: details', 'time' => '12:34:56'],
            ],
            'values are trimmed' => [
                "key1:   value with spaces   \nkey2: normal",
                ['key1' => 'value with spaces', 'key2' => 'normal'],
            ],
            'empty values' => [
                "key-with-empty:\nkey-with-value: something",
                ['key-with-empty' => '', 'key-with-value' => 'something'],
            ],
            'lines without colons are ignored' => [
                "valid-key: value\nno colon here\nanother-key: value2",
                ['valid-key' => 'value', 'another-key' => 'value2'],
            ],
            'trailing newlines ignored' => [
                "key: value\n\n\n",
                ['key' => 'value'],
            ],
        ];
    }

    /**
     * @dataProvider metadataProvider
     */
    public function testMetadataParsing(string $content, array $expected): void
    {
        $filename = $this->writeMetaFile($content);
        $result = $this->callReadMetadata($filename);

        $this->assertIsArray($result);
        $this->assertEquals($expected, $result);
    }

    public function testNonExistentFileReturnsNull(): void
    {
        $this->assertNull($this->callReadMetadata('/nonexistent/path/file.meta'));
    }

    public function testUnreadableFileReturnsNull(): void
    {
        $filename = $this->writeMetaFile('key: value');
        chmod($filename, 0000);
        $this->assertNull($this->callReadMetadata($filename));
    }

    public function testEmptyFileReturnsEmptyArray(): void
    {
        $filename = $this->writeMetaFile('');
        $result = $this->callReadMetadata($filename);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
