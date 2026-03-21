<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Mapping;

use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use PHPUnit\Framework\TestCase;

final class AllocationStatsUrgencyProjectionCodeTest extends TestCase
{
    public function testMapsDbValuesOneToThree(): void
    {
        self::assertSame(AllocationStatsUrgencyProjectionCode::Emergency, AllocationStatsUrgencyProjectionCode::tryFromDbValue(1));
        self::assertSame(AllocationStatsUrgencyProjectionCode::Inpatient, AllocationStatsUrgencyProjectionCode::tryFromDbValue('2'));
        self::assertSame(2, AllocationStatsUrgencyProjectionCode::tryFromDbValue('2')->value);
        self::assertNull(AllocationStatsUrgencyProjectionCode::tryFromDbValue(0));
        self::assertNull(AllocationStatsUrgencyProjectionCode::tryFromDbValue(4));
        self::assertNull(AllocationStatsUrgencyProjectionCode::tryFromDbValue(null));
    }
}
