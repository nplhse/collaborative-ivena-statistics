<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\DataQuality;

use App\Statistics\DataQuality\DataQualityLevel;
use App\Statistics\DataQuality\Dto\DataQualityHospitalSnapshot;
use App\Statistics\DataQuality\RepresentativenessDataQualityCalculator;
use PHPUnit\Framework\TestCase;

final class RepresentativenessDataQualityCalculatorTest extends TestCase
{
    private RepresentativenessDataQualityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new RepresentativenessDataQualityCalculator();
    }

    public function testHighWhenDistributionsMatch(): void
    {
        $population = [
            $this->hospital(1, 'Small', 'Full', 'Urban', 'Gießen'),
            $this->hospital(2, 'Large', 'Full', 'Rural', 'Kassel'),
        ];
        $participants = [1, 2];

        $result = $this->calculator->calculate($population, $participants);

        self::assertSame(DataQualityLevel::High, $result->level);
        self::assertSame(0.0, $result->averageDifference);
    }

    public function testCalculatesTotalAbsoluteDifference(): void
    {
        $population = array_merge(
            array_map(fn (int $id): DataQualityHospitalSnapshot => $this->hospital($id, 'Small', 'Full', 'Urban', 'A'), range(1, 80)),
            array_map(fn (int $id): DataQualityHospitalSnapshot => $this->hospital($id, 'Large', 'Full', 'Urban', 'A'), range(81, 100)),
        );
        $participants = range(81, 100);

        $result = $this->calculator->calculate($population, $participants);
        $sizeDimension = $result->dimensions[0];

        self::assertGreaterThan(0.20, $sizeDimension->difference);
        self::assertSame(DataQualityLevel::Low, $sizeDimension->level);
        self::assertNotEmpty($sizeDimension->topDeviations);
    }

    public function testCapsOverallAtMediumWhenAnyDimensionIsNotHigh(): void
    {
        $population = array_merge(
            array_map(fn (int $id): DataQualityHospitalSnapshot => $this->hospital($id, 'Large', 'Full', 'Urban', 'A'), range(1, 50)),
            array_map(fn (int $id): DataQualityHospitalSnapshot => $this->hospital($id, 'Large', 'Full', 'Urban', 'B'), range(51, 100)),
        );
        $participants = array_merge(range(1, 45), range(51, 55));

        $result = $this->calculator->calculate($population, $participants);

        self::assertSame(DataQualityLevel::Low, $result->dimensions[3]->level);
        self::assertSame(DataQualityLevel::High, $result->dimensions[0]->level);
        self::assertSame(DataQualityLevel::Medium, $result->level);
    }

    private function hospital(int $id, string $size, string $careLevel, string $urbanity, string $landkreis): DataQualityHospitalSnapshot
    {
        return new DataQualityHospitalSnapshot($id, $size, $careLevel, $urbanity, $landkreis);
    }
}
