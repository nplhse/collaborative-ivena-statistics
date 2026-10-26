<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Domain\ValueObject;

use App\Import\Domain\ValueObject\ImportDataCoverage;
use App\Import\Domain\ValueObject\ImportDataCoverageGap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportDataCoverage::class)]
#[CoversClass(ImportDataCoverageGap::class)]
final class ImportDataCoverageTest extends TestCase
{
    #[Test]
    public function summarisesDaysWithAndWithoutData(): void
    {
        $coverage = $this->coverage([
            '2026-09-30' => 4,
            '2026-09-01' => 10,
            '2026-09-02' => 7,
            '2026-09-12' => 1,
        ]);

        self::assertSame(['2026-09-01', '2026-09-02', '2026-09-12', '2026-09-30'], array_keys($coverage->dailyCounts), 'Days are sorted.');
        self::assertSame('2026-09-01', $coverage->firstDay()?->format('Y-m-d'));
        self::assertSame('2026-09-30', $coverage->lastDay()?->format('Y-m-d'));
        self::assertSame(30, $coverage->periodDays());
        self::assertSame(4, $coverage->daysWithData());
        self::assertSame(26, $coverage->daysWithoutData());
        self::assertSame(10, $coverage->maxDailyCount());
        self::assertSame(7, $coverage->countOn(new \DateTimeImmutable('2026-09-02 18:00')));
        self::assertSame(0, $coverage->countOn(new \DateTimeImmutable('2026-09-03')));
    }

    #[Test]
    public function groupsConsecutiveMissingDaysIntoGaps(): void
    {
        $gaps = $this->coverage([
            '2026-09-01' => 1,
            '2026-09-02' => 1,
            '2026-09-05' => 1,
            '2026-09-07' => 1,
        ])->gaps();

        self::assertSame(
            [['2026-09-03', '2026-09-04', 2], ['2026-09-06', '2026-09-06', 1]],
            array_map(static fn (ImportDataCoverageGap $gap): array => [$gap->firstDay->format('Y-m-d'), $gap->lastDay->format('Y-m-d'), $gap->days()], $gaps),
        );
    }

    #[Test]
    public function gapsSpanMonthBoundariesAndDaylightSavingTime(): void
    {
        $coverage = $this->coverage(['2026-03-28' => 1, '2026-04-02' => 1]);

        self::assertSame(6, $coverage->periodDays());
        self::assertCount(1, $coverage->gaps());
        self::assertSame(4, $coverage->gaps()[0]->days());
    }

    #[Test]
    public function continuousCoverageHasNoGaps(): void
    {
        $coverage = $this->coverage(['2026-09-01' => 1, '2026-09-02' => 1, '2026-09-03' => 1]);

        self::assertSame([], $coverage->gaps());
        self::assertSame(0, $coverage->daysWithoutData());
    }

    #[Test]
    public function emptyCoverageHasNoPeriod(): void
    {
        $coverage = ImportDataCoverage::empty();

        self::assertTrue($coverage->isEmpty());
        self::assertNull($coverage->firstDay());
        self::assertSame(0, $coverage->periodDays());
        self::assertSame(0, $coverage->daysWithoutData());
        self::assertSame(0, $coverage->maxDailyCount());
        self::assertSame([], $coverage->gaps());
    }

    #[Test]
    public function roundTripsThroughItsStoredForm(): void
    {
        $coverage = new ImportDataCoverage(
            3,
            new \DateTimeImmutable('2026-09-01 00:20:00'),
            new \DateTimeImmutable('2026-09-03 23:40:00'),
            ['2026-09-03' => 1, '2026-09-01' => 2],
        );

        $data = $coverage->toArray();
        self::assertSame([
            'version' => 1,
            'records' => 3,
            'first_at' => '2026-09-01 00:20:00',
            'last_at' => '2026-09-03 23:40:00',
            'daily_counts' => ['2026-09-01' => 2, '2026-09-03' => 1],
        ], $data);
        self::assertEquals($coverage, ImportDataCoverage::fromArray($data));
        self::assertEquals(ImportDataCoverage::empty(), ImportDataCoverage::fromArray(ImportDataCoverage::empty()->toArray()));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreadableData(): iterable
    {
        $valid = ['version' => 1, 'records' => 1, 'first_at' => '2026-09-01 10:00:00', 'last_at' => '2026-09-01 10:00:00', 'daily_counts' => ['2026-09-01' => 1]];

        yield 'null' => [null];
        yield 'unknown version' => [['version' => 2] + $valid];
        yield 'invalid day' => [['daily_counts' => ['2026-02-30' => 1]] + $valid];
        yield 'zero count' => [['daily_counts' => ['2026-09-01' => 0]] + $valid];
        yield 'string count' => [['daily_counts' => ['2026-09-01' => '1']] + $valid];
        yield 'missing timestamps' => [['first_at' => null] + $valid];
        yield 'invalid timestamp' => [['last_at' => 'yesterday'] + $valid];
    }

    #[Test]
    #[DataProvider('unreadableData')]
    public function readsUnknownOrMalformedDataAsNotCalculated(mixed $data): void
    {
        self::assertNull(ImportDataCoverage::fromArray($data));
    }

    #[Test]
    public function rejectsEndBeforeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ImportDataCoverage(1, new \DateTimeImmutable('2026-09-02'), new \DateTimeImmutable('2026-09-01'), ['2026-09-01' => 1]);
    }

    /**
     * @param array<string, int> $dailyCounts
     */
    private function coverage(array $dailyCounts): ImportDataCoverage
    {
        $days = array_keys($dailyCounts);
        sort($days);

        return new ImportDataCoverage(
            array_sum($dailyCounts),
            new \DateTimeImmutable($days[0].' 00:00:00'),
            new \DateTimeImmutable($days[\count($days) - 1].' 23:59:00'),
            $dailyCounts,
        );
    }
}
