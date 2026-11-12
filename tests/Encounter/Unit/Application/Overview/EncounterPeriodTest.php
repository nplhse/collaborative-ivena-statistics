<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Overview;

use App\Encounter\Application\Overview\EncounterPeriod;
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
}
