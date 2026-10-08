<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileCourseBandBuilder;
use PHPUnit\Framework\TestCase;

final class ClosureProfileCourseBandBuilderTest extends TestCase
{
    public function testStartAlignedBandUsesZeroHourAsStart(): void
    {
        $points = $this->samplePoints();

        self::assertSame([[3, 4]], ClosureProfileCourseBandBuilder::bands($points, false));
        self::assertSame('+0 h', ClosureProfileCourseBandBuilder::anchorCategory($points));
    }

    public function testEndAlignedBandStopsBeforeZeroHour(): void
    {
        $points = $this->samplePoints();

        self::assertSame([[2, 4]], ClosureProfileCourseBandBuilder::bands($points, true));
    }

    /**
     * @return list<array{offset: int, closureShare: float}>
     */
    private function samplePoints(): array
    {
        return [
            ['offset' => -3, 'closureShare' => 0.0],
            ['offset' => -2, 'closureShare' => 0.0],
            ['offset' => -1, 'closureShare' => 0.25],
            ['offset' => 0, 'closureShare' => 1.0],
            ['offset' => 1, 'closureShare' => 0.5],
            ['offset' => 2, 'closureShare' => 0.0],
        ];
    }
}
