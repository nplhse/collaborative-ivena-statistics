<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\DataQuality;

use App\Statistics\DataQuality\AllocationVolumeDataQualityCalculator;
use App\Statistics\DataQuality\DataQualityLevel;
use PHPUnit\Framework\TestCase;

final class AllocationVolumeDataQualityCalculatorTest extends TestCase
{
    private AllocationVolumeDataQualityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AllocationVolumeDataQualityCalculator();
    }

    public function testLowWhenLessThanHalfMeetVolumeThreshold(): void
    {
        $counts = [1 => 50, 2 => 80, 3 => 120, 4 => 10];

        $result = $this->calculator->calculate($counts, [1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D']);

        self::assertSame(DataQualityLevel::Low, $result->level);
        self::assertSame(0.25, $result->shareHospitalsWithSufficientAllocations);
        self::assertSame(100, $result->minAllocationsPerHospital);
        self::assertCount(3, $result->hospitalsBelowThreshold);
    }

    public function testHighWhenAtLeastEightyPercentMeetVolumeThreshold(): void
    {
        $counts = [];
        $names = [];
        for ($i = 1; $i <= 10; ++$i) {
            $counts[$i] = $i <= 9 ? 150 : 20;
            $names[$i] = 'Hospital '.$i;
        }

        $result = $this->calculator->calculate($counts, $names);

        self::assertSame(DataQualityLevel::High, $result->level);
        self::assertSame(137.0, $result->meanAllocationsPerHospital);
    }
}
