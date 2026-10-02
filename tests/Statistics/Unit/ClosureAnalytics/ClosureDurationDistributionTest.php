<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\ClosureDurationDistribution;
use App\Statistics\HospitalPopulation\Application\DescriptiveStatisticsCalculator;
use PHPUnit\Framework\TestCase;

final class ClosureDurationDistributionTest extends TestCase
{
    public function testTukeyWhiskersSeparateOutliers(): void
    {
        $group = $this->distribution()->summarize('dept', 'Cardiology', [1, 2, 3, 4, 5, 6, 7, 100]);

        self::assertTrue($group->showBox);
        self::assertSame(8, $group->count);
        self::assertEqualsWithDelta(2.75, $group->lowerQuartileSeconds, 0.0001);
        self::assertEqualsWithDelta(4.5, $group->medianSeconds, 0.0001);
        self::assertEqualsWithDelta(6.25, $group->upperQuartileSeconds, 0.0001);
        self::assertEqualsWithDelta(1.0, $group->whiskerLowSeconds, 0.0001);
        self::assertEqualsWithDelta(7.0, $group->whiskerHighSeconds, 0.0001);
        self::assertSame([100.0], $group->outlierSeconds);
        self::assertSame(1, $group->minimumSeconds);
        self::assertSame(100, $group->maximumSeconds);
    }

    public function testFewerThanFiveObservationsStayIndividualValues(): void
    {
        $group = $this->distribution()->summarize('dept', 'Neurology', [30, 10, 20, 40]);

        self::assertFalse($group->showBox);
        self::assertNull($group->whiskerLowSeconds);
        self::assertNull($group->whiskerHighSeconds);
        self::assertSame([], $group->outlierSeconds);
        self::assertSame([10, 20, 30, 40], $group->valueSeconds);
        self::assertSame(4, $group->count);
        self::assertSame(10, $group->minimumSeconds);
        self::assertSame(40, $group->maximumSeconds);
    }

    private function distribution(): ClosureDurationDistribution
    {
        return new ClosureDurationDistribution(new DescriptiveStatisticsCalculator());
    }
}
