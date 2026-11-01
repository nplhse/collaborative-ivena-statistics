<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\Application\Service;

use App\Analytics\Application\DTO\AccessPeriod;
use App\Analytics\Application\Service\AccessTrendBuilder;
use App\Analytics\Domain\AnalyticsCalendar;
use PHPUnit\Framework\TestCase;

final class AccessTrendBuilderTest extends TestCase
{
    private AccessTrendBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new AccessTrendBuilder();
    }

    public function testNoDaysMeansNothingCounted(): void
    {
        $trend = $this->builder->build([], $this->day('2026-11-15'));

        self::assertFalse($trend->hasData());
        self::assertNull($trend->countingSince);
        self::assertSame(0, $trend->total());
        self::assertSame([], $trend->years);
        self::assertSame([], $trend->months);
    }

    public function testSumsMonthsAndYearsAcrossTheYearBoundary(): void
    {
        // Counting starts on 2026-11-20. December has no counted day, so it is zero, not missing.
        $days = [
            ['date' => '2026-11-20', 'authenticated' => 2, 'anonymous' => 5],
            ['date' => '2026-11-30', 'authenticated' => 1, 'anonymous' => 0],
            ['date' => '2027-01-01', 'authenticated' => 3, 'anonymous' => 4],
            ['date' => '2027-02-10', 'authenticated' => 0, 'anonymous' => 6],
        ];

        $trend = $this->builder->build($days, $this->day('2027-02-10'));

        self::assertSame('2026-11-20', $trend->countingSince?->format('Y-m-d'));
        self::assertSame(6, $trend->authenticated);
        self::assertSame(15, $trend->anonymous);
        self::assertSame(21, $trend->total());

        self::assertSame(
            [
                ['2026-11', 3, 5, false, true],
                ['2026-12', 0, 0, false, false],
                ['2027-01', 3, 4, false, false],
                ['2027-02', 0, 6, true, false],
            ],
            array_map($this->summarise(...), $trend->months),
        );
        self::assertSame(
            [
                ['2026', 3, 5, false, true],
                ['2027', 3, 10, true, false],
            ],
            array_map($this->summarise(...), $trend->years),
        );
    }

    public function testCompleteOnlyWhenOverAndFullyCounted(): void
    {
        $days = [
            ['date' => '2026-09-01', 'authenticated' => 1, 'anonymous' => 1],
            ['date' => '2026-10-31', 'authenticated' => 1, 'anonymous' => 1],
        ];

        $trend = $this->builder->build($days, $this->day('2026-11-01'));

        // Counting started on the first day of September, so September is complete.
        self::assertTrue($trend->months[0]->isComplete());
        self::assertTrue($trend->months[1]->isComplete());
        // November has started today and has no counted day yet: zero and not over.
        self::assertSame('2026-11', $trend->months[2]->key);
        self::assertSame(0, $trend->months[2]->total());
        self::assertFalse($trend->months[2]->isComplete());
        // The year 2026 started before the counting and is not over.
        self::assertFalse($trend->years[0]->isComplete());
    }

    public function testIgnoresDaysAfterToday(): void
    {
        $days = [
            ['date' => '2026-11-01', 'authenticated' => 1, 'anonymous' => 0],
            ['date' => '2026-11-03', 'authenticated' => 9, 'anonymous' => 9],
        ];

        $trend = $this->builder->build($days, $this->day('2026-11-02'));

        self::assertSame(1, $trend->total());
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: bool, 4: bool} key, signed in, anonymous, current, starts before counting
     */
    private function summarise(AccessPeriod $period): array
    {
        return [$period->key, $period->authenticated, $period->anonymous, $period->isCurrent, $period->startsBeforeCounting];
    }

    private function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date.' 12:00:00', AnalyticsCalendar::timezone());
    }
}
