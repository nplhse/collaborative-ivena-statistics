<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Matching;

use App\Encounter\Application\Matching\MatchScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MatchScopeTest extends TestCase
{
    public function testDaysAreDistinctSortedAndGroupedIntoRanges(): void
    {
        $scope = new MatchScope(7, ['2026-10-14', '2026-10-12', '2026-10-13', '2026-10-20', '2026-10-12']);

        self::assertSame(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-20'], $scope->days);
        self::assertSame(
            [['2026-10-12 00:00', '2026-10-15 00:00'], ['2026-10-20 00:00', '2026-10-21 00:00']],
            array_map(static fn (array $r): array => [$r[0]->format('Y-m-d H:i'), $r[1]->format('Y-m-d H:i')], $scope->ranges()),
        );
    }

    public function testVisitDaysAreWidenedByOneDayOnBothSides(): void
    {
        self::assertSame(
            ['2026-10-31', '2026-11-01', '2026-11-02', '2026-11-04', '2026-11-05', '2026-11-06'],
            MatchScope::aroundVisitDays(7, ['2026-11-01', '2026-11-05'])->days,
        );
    }

    public function testPeriodCoversEveryDayInclusive(): void
    {
        self::assertSame(['2026-10-30', '2026-10-31', '2026-11-01'], MatchScope::forPeriod(7, new \DateTimeImmutable('2026-10-30'), new \DateTimeImmutable('2026-11-01'))->days);
    }

    #[DataProvider('invalidDays')]
    public function testInvalidDaysAreRefused(string $day): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MatchScope(7, [$day]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDays(): iterable
    {
        yield 'month 13' => ['2026-13-01'];
        yield 'no date' => ['yesterday'];
        yield 'with time' => ['2026-10-12 10:00'];
    }
}
