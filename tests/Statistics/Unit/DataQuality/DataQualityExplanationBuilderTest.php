<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\DataQuality;

use App\Statistics\DataQuality\DataQualityExplanationBuilder;
use App\Statistics\DataQuality\DataQualityLevel;
use App\Statistics\DataQuality\Dto\AllocationHistogramBucket;
use App\Statistics\DataQuality\Dto\AllocationVolumeResult;
use App\Statistics\DataQuality\Dto\CoverageResult;
use App\Statistics\DataQuality\Dto\RepresentativenessResult;
use App\Statistics\DataQuality\Dto\SubgroupCell;
use App\Statistics\DataQuality\Dto\SubgroupSupportResult;
use PHPUnit\Framework\TestCase;

final class DataQualityExplanationBuilderTest extends TestCase
{
    private DataQualityExplanationBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new DataQualityExplanationBuilder();
    }

    public function testHighExplanationKey(): void
    {
        [$key] = $this->builder->build(
            DataQualityLevel::High,
            $this->coverage(DataQualityLevel::High),
            new RepresentativenessResult(DataQualityLevel::High, 0.05, []),
            $this->subgroup(DataQualityLevel::High),
            $this->allocation(DataQualityLevel::High),
        );

        self::assertSame('stats.data_quality.explanation.high', $key);
    }

    public function testWeakSubgroupExplanationKey(): void
    {
        [$key] = $this->builder->build(
            DataQualityLevel::Medium,
            $this->coverage(DataQualityLevel::Medium),
            new RepresentativenessResult(DataQualityLevel::Medium, 0.15, []),
            new SubgroupSupportResult(
                DataQualityLevel::Low,
                24,
                3,
                1,
                0.40,
                0.33,
                [],
                [new SubgroupCell('size_care:Small×Full', 10, 2, false)],
            ),
            $this->allocation(DataQualityLevel::High),
        );

        self::assertSame('stats.data_quality.explanation.weak_subgroups', $key);
    }

    private function coverage(DataQualityLevel $level): CoverageResult
    {
        return new CoverageResult($level, 100, 30, 0.30, 30.0);
    }

    private function subgroup(DataQualityLevel $level): SubgroupSupportResult
    {
        return new SubgroupSupportResult($level, 24, 10, 10, 0.85, 0.85, [], []);
    }

    private function allocation(DataQualityLevel $level): AllocationVolumeResult
    {
        return new AllocationVolumeResult(
            $level,
            500,
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
    }
}
