<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\DataQuality;

use App\Statistics\DataQuality\DataQualityLevel;
use App\Statistics\DataQuality\Dto\DataQualityHospitalSnapshot;
use App\Statistics\DataQuality\SubgroupSupportDataQualityCalculator;
use PHPUnit\Framework\TestCase;

final class SubgroupSupportDataQualityCalculatorTest extends TestCase
{
    private SubgroupSupportDataQualityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new SubgroupSupportDataQualityCalculator();
    }

    public function testIdentifiesWeaklySupportedCells(): void
    {
        $population = [
            new DataQualityHospitalSnapshot(1, 'Small', 'Full', 'Rural', 'A'),
            new DataQualityHospitalSnapshot(2, 'Small', 'Full', 'Rural', 'A'),
            new DataQualityHospitalSnapshot(3, 'Small', 'Full', 'Rural', 'A'),
            new DataQualityHospitalSnapshot(4, 'Large', 'Basic', 'Urban', 'B'),
        ];

        $result = $this->calculator->calculate($population, [4]);

        self::assertNotEmpty($result->weaklySupportedCells);
        self::assertLessThan(0.80, $result->supportedPopulationShare);
    }

    public function testHighWhenMostPopulationCellsSupported(): void
    {
        $population = [];
        $participants = [];
        for ($i = 1; $i <= 10; ++$i) {
            $population[] = new DataQualityHospitalSnapshot($i, 'Large', 'Full', 'Urban', 'A');
            $participants[] = $i;
        }

        $result = $this->calculator->calculate($population, $participants);

        self::assertSame(DataQualityLevel::High, $result->level);
        self::assertGreaterThanOrEqual(0.80, $result->supportedPopulationShare);
    }

    public function testHighWhenAllRelevantCellsSupportedInNarrowScope(): void
    {
        $population = [
            new DataQualityHospitalSnapshot(1, 'Large', 'Full', 'Urban', 'A'),
            new DataQualityHospitalSnapshot(2, 'Large', 'Full', 'Urban', 'A'),
            new DataQualityHospitalSnapshot(3, 'Large', 'Full', 'Urban', 'A'),
        ];

        $result = $this->calculator->calculate($population, [1, 2, 3]);

        self::assertSame(2, $result->relevantCellsCount);
        self::assertSame(2, $result->supportedCellsCount);
        self::assertSame(1.0, $result->supportedRelevantCellShare);
        self::assertSame(DataQualityLevel::High, $result->level);
        self::assertSame([], $result->weaklySupportedCells);
    }

    public function testUsesPopulationShareWhenManyRelevantCells(): void
    {
        $population = [];
        $participants = [];
        for ($i = 1; $i <= 20; ++$i) {
            $size = match ($i % 3) {
                0 => 'Small',
                1 => 'Medium',
                default => 'Large',
            };
            $care = match ($i % 4) {
                0 => 'Basic',
                1 => 'Extended',
                2 => 'Full',
                default => 'unknown',
            };
            $urbanity = match ($i % 3) {
                0 => 'Urban',
                1 => 'Mixed',
                default => 'Rural',
            };
            $population[] = new DataQualityHospitalSnapshot($i, $size, $care, $urbanity, 'A');
            if ($i <= 15) {
                $participants[] = $i;
            }
        }

        $result = $this->calculator->calculate($population, $participants);

        self::assertGreaterThanOrEqual(5, $result->relevantCellsCount);
    }
}
