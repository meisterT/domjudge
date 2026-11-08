<?php declare(strict_types=1);

namespace DOMjudge\Tests\Unit;

use DOMjudge\CompareMetadata;
use DOMjudge\ProgramMetadata;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class MetadataParsingTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function completeProgramMeta(): array
    {
        return [
            'exitcode' => '0',
            'cpu-time' => '0.010',
            'wall-time' => '0.020',
            'time-used' => 'cpu-time',
            'memory-bytes' => '1024',
            'time-result' => '',
            'stdout-bytes' => '3',
            'stderr-bytes' => '0',
        ];
    }

    public function testCompleteProgramMetadata(): void
    {
        $meta = ProgramMetadata::fromArray(self::completeProgramMeta());
        self::assertSame('0', $meta->exitcode);
        self::assertSame('0.010', $meta->cpuTime);
        self::assertSame('', $meta->outputTruncated);
    }

    public function testIncompleteProgramMetadataNamesMissingFields(): void
    {
        // Runguard writes the exit code first and the byte counts last.
        $meta = self::completeProgramMeta();
        unset($meta['stdout-bytes'], $meta['stderr-bytes']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('program metadata is incomplete, missing: stdout-bytes, stderr-bytes');
        ProgramMetadata::fromArray($meta);
    }

    public function testEmptyProgramMetadata(): void
    {
        $this->expectException(UnexpectedValueException::class);
        ProgramMetadata::fromArray([]);
    }

    public function testCompleteCompareMetadata(): void
    {
        $meta = CompareMetadata::fromArray(['exitcode' => '42', 'validator-exited-first' => 'true']);
        self::assertSame('42', $meta->exitcode);
        self::assertTrue($meta->validatorExitedFirst);
    }

    public function testIncompleteCompareMetadata(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('compare metadata is incomplete, missing: exitcode');
        CompareMetadata::fromArray(['wall-time' => '0.1']);
    }
}
