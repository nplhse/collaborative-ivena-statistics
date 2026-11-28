<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Application\Service;

use App\Admin\Application\Service\JobRun\JobRunFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Durations and memory of job runs; the metrics table needs the hospital names and is tested in
 * tests/Admin/Integration/JobRun/JobRunFormatterTest.
 */
final class JobRunFormatterTest extends TestCase
{
    #[DataProvider('durations')]
    public function testDuration(?int $ms, string $expected): void
    {
        self::assertSame($expected, JobRunFormatter::duration($ms));
    }

    /**
     * @return iterable<array{?int, string}>
     */
    public static function durations(): iterable
    {
        yield [null, '—'];
        yield [0, '0 ms'];
        yield [999, '999 ms'];
        yield [1_000, '1.0 s'];
        yield [59_949, '59.9 s'];
        yield [60_000, '1 min 00 s'];
        yield [979_000, '16 min 19 s'];
        yield [3_600_000, '1 h 00 min'];
        yield [5_461_000, '1 h 31 min'];
    }

    public function testMemory(): void
    {
        self::assertSame('—', JobRunFormatter::memory(null));
        self::assertSame('106.0 MB', JobRunFormatter::memory(111_149_056));
    }
}
