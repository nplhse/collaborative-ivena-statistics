<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Overview;

use App\Encounter\Application\Overview\EncounterPeriod;
use App\Encounter\Application\Overview\EncounterPeriodPreset;
use PHPUnit\Framework\TestCase;

final class EncounterPeriodTest extends TestCase
{
    public function testBothDaysAreIncludedAsAHalfOpenWindow(): void
    {
        $period = EncounterPeriod::fromQuery('2026-10-10', '2026-10-12');

        self::assertSame('2026-10-10 00:00', $period->from?->format('Y-m-d H:i'));
        self::assertSame('2026-10-13 00:00', $period->endExclusive()?->format('Y-m-d H:i'));
        self::assertFalse($period->isAllTime());
    }

    public function testSwappedBoundsAreOrdered(): void
    {
        $period = EncounterPeriod::fromQuery('2026-10-12', '2026-10-10');

        self::assertSame('2026-10-10', $period->from?->format('Y-m-d'));
        self::assertSame('2026-10-12', $period->to?->format('Y-m-d'));
    }

    public function testInvalidOrMissingValuesLeaveTheBoundOpen(): void
    {
        $period = EncounterPeriod::fromQuery('2026-02-30', 'yesterday');
        self::assertTrue($period->isAllTime());
        self::assertNull($period->endExclusive());

        $open = EncounterPeriod::fromQuery(null, '2026-10-12');
        self::assertNull($open->from);
        self::assertSame('2026-10-13', $open->endExclusive()?->format('Y-m-d'));
    }

    /**
     * Today: 12 November 2026.
     */
    public function testPresetsFillWholeCalendarDays(): void
    {
        $today = new \DateTimeImmutable('2026-11-12 15:30');

        $last12 = EncounterPeriod::fromPreset('last_12_months', null, null, null, null, $today);
        self::assertSame(['2025-12-01', null], $this->bounds($last12), 'First day of the month eleven months back, open end, as in Statistics.');
        self::assertSame(['period' => 'last_12_months'], $last12->queryParameters());

        $year = EncounterPeriod::fromPreset('year', '2026', null, null, null, $today);
        self::assertSame(['2026-01-01', '2026-12-31'], $this->bounds($year));
        self::assertSame(['period' => 'year', 'year' => '2026'], $year->queryParameters());

        $february = EncounterPeriod::fromPreset('month', null, '2028-02', null, null, $today);
        self::assertSame(['2028-02-01', '2028-02-29'], $this->bounds($february), 'Leap year.');
        self::assertSame(EncounterPeriodPreset::Month, $february->preset);

        $custom = EncounterPeriod::fromPreset('custom', null, null, '2026-10-10', '2026-10-12', $today);
        self::assertSame(['period' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-12'], $custom->queryParameters());
        self::assertSame(['2026-10-10', '2026-10-12'], $this->bounds(EncounterPeriod::fromPreset(null, null, null, '2026-10-10', '2026-10-12', $today)), 'Dates without preset are an own period.');
    }

    public function testIncompletePresetsFallBackToAllData(): void
    {
        $today = new \DateTimeImmutable('2026-11-12');

        foreach ([['year', '26', null], ['month', null, '2026-13'], ['custom', null, null], ['unknown', null, null], [null, null, null]] as [$preset, $year, $month]) {
            $period = EncounterPeriod::fromPreset($preset, $year, $month, null, null, $today);
            self::assertTrue($period->isAllTime(), (string) $preset);
            self::assertSame([], $period->queryParameters());
        }
    }

    /**
     * @return array{?string, ?string}
     */
    private function bounds(EncounterPeriod $period): array
    {
        return [$period->from?->format('Y-m-d'), $period->to?->format('Y-m-d')];
    }
}
