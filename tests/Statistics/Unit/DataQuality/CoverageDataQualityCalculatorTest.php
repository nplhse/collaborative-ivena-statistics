<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\DataQuality;

use App\Statistics\DataQuality\CoverageDataQualityCalculator;
use App\Statistics\DataQuality\DataQualityLevel;
use PHPUnit\Framework\TestCase;

final class CoverageDataQualityCalculatorTest extends TestCase
{
    private CoverageDataQualityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CoverageDataQualityCalculator();
    }

    public function testLowWhenCoverageBelowTwentyPercent(): void
    {
        $result = $this->calculator->calculate(100, 15);

        self::assertSame(DataQualityLevel::Low, $result->level);
        self::assertSame(15.0, $result->coveragePercentage);
    }

    public function testMediumWhenCoverageBelowNinetyPercent(): void
    {
        $result = $this->calculator->calculate(100, 40);

        self::assertSame(DataQualityLevel::Medium, $result->level);
        self::assertSame(40.0, $result->coveragePercentage);
    }

    public function testMediumWhenCoverageBetweenTwentyAndNinetyPercent(): void
    {
        $result = $this->calculator->calculate(100, 50);

        self::assertSame(DataQualityLevel::Medium, $result->level);
        self::assertSame(50.0, $result->coveragePercentage);
    }

    public function testHighWhenCoverageAtLeastNinetyPercent(): void
    {
        $result = $this->calculator->calculate(100, 90);

        self::assertSame(DataQualityLevel::High, $result->level);
        self::assertSame(100, $result->totalHospitals);
        self::assertSame(90, $result->participatingHospitals);
    }
}
