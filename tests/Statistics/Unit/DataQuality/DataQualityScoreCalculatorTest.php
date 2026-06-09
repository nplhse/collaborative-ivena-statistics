<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\DataQuality;

use App\Statistics\DataQuality\AllocationVolumeDataQualityCalculator;
use App\Statistics\DataQuality\CoverageDataQualityCalculator;
use App\Statistics\DataQuality\DataQualityLevel;
use App\Statistics\DataQuality\DataQualityScoreCalculator;
use App\Statistics\DataQuality\Dto\AllocationHistogramBucket;
use App\Statistics\DataQuality\Dto\AllocationVolumeResult;
use App\Statistics\DataQuality\Dto\CoverageResult;
use App\Statistics\DataQuality\Dto\RepresentativenessResult;
use App\Statistics\DataQuality\Dto\SubgroupSupportResult;
use App\Statistics\DataQuality\RepresentativenessDataQualityCalculator;
use App\Statistics\DataQuality\SubgroupSupportDataQualityCalculator;
use PHPUnit\Framework\TestCase;

final class DataQualityScoreCalculatorTest extends TestCase
{
    private DataQualityScoreCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DataQualityScoreCalculator();
    }

    public function testCapRuleLimitsOverallToMediumWhenAnyDimensionIsLow(): void
    {
        $coverage = new CoverageResult(DataQualityLevel::High, 100, 80, 0.8, 80.0);
        $representativeness = new RepresentativenessResult(DataQualityLevel::High, 0.05, []);
        $subgroup = new SubgroupSupportResult(DataQualityLevel::Low, 24, 8, 2, 0.40, 0.25, [], []);
        $allocation = new AllocationVolumeResult(
            DataQualityLevel::High,
            1000,
            50.0,
            45.0,
            20,
            60,
            90,
            120,
            0.90,
            100,
            10,
            [],
            [new AllocationHistogramBucket('100+', 9, true)],
        );

        $overall = $this->calculator->calculateOverall($coverage, $representativeness, $subgroup, $allocation);

        self::assertSame(DataQualityLevel::Medium, $overall);
    }

    public function testHighOverallWhenAllDimensionsHigh(): void
    {
        $coverage = new CoverageDataQualityCalculator()->calculate(100, 90);
        $population = array_map(
            static fn (int $id): \App\Statistics\DataQuality\Dto\DataQualityHospitalSnapshot => new \App\Statistics\DataQuality\Dto\DataQualityHospitalSnapshot($id, 'Large', 'Full', 'Urban', 'A'),
            range(1, 10),
        );
        $participants = range(1, 10);
        $representativeness = new RepresentativenessDataQualityCalculator()->calculate($population, $participants);
        $subgroup = new SubgroupSupportDataQualityCalculator()->calculate($population, $participants);
        $allocation = new AllocationVolumeDataQualityCalculator()->calculate(
            array_fill_keys($participants, 120),
            array_fill_keys($participants, 'H'),
        );

        $overall = $this->calculator->calculateOverall($coverage, $representativeness, $subgroup, $allocation);

        self::assertSame(DataQualityLevel::High, $overall);
    }
}
