<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Overview;

use App\Encounter\Application\Overview\EncounterPeriod;
use PHPUnit\Framework\TestCase;

final class EncounterPeriodTest extends TestCase
{
    /**
     * Statistics windows are half-open with midnight bounds; the encounter period keeps whole days.
     */
    public function testTakesTheStatisticsWindowAsWholeDays(): void
    {
        $october = EncounterPeriod::fromBounds(new \DateTimeImmutable('2026-10-01 00:00'), new \DateTimeImmutable('2026-11-01 00:00'));
        self::assertSame(['2026-10-01', '2026-10-31'], [$october->from?->format('Y-m-d'), $october->to?->format('Y-m-d')]);
        self::assertSame('2026-11-01 00:00', $october->endExclusive()?->format('Y-m-d H:i'));

        $last12 = EncounterPeriod::fromBounds(new \DateTimeImmutable('2025-12-01 00:00'), null);
        self::assertNull($last12->to);
        self::assertTrue(EncounterPeriod::fromBounds(null, null)->isAllTime());
    }
}
