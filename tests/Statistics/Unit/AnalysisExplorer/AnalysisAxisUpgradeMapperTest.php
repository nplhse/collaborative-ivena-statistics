<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AnalysisAxisUpgradeMapper;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use PHPUnit\Framework\TestCase;

final class AnalysisAxisUpgradeMapperTest extends TestCase
{
    private AnalysisAxisUpgradeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new AnalysisAxisUpgradeMapper();
    }

    public function testTimeDimensionMapsToRowAxisOnly(): void
    {
        [$rowAxis, $columnAxis] = $this->mapper->fromLegacyDimension(
            AnalysisDimensionKey::Time,
            AnalysisDimensionGrain::Month,
        );

        self::assertSame(AnalysisDimensionKey::Time, $rowAxis->dimensionKey);
        self::assertSame(AnalysisDimensionGrain::Month, $rowAxis->resolvedGrain());
        self::assertNull($columnAxis);
    }

    public function testBreakdownWithTemporalGrainPreservesOverTimeOrientation(): void
    {
        [$rowAxis, $columnAxis] = $this->mapper->fromLegacyDimension(
            AnalysisDimensionKey::Gender,
            AnalysisDimensionGrain::Month,
        );

        self::assertSame(AnalysisDimensionKey::Time, $rowAxis->dimensionKey);
        self::assertSame(AnalysisDimensionGrain::Month, $rowAxis->resolvedGrain());
        self::assertSame(AnalysisDimensionKey::Gender, $columnAxis?->dimensionKey);
    }

    public function testBreakdownWithTotalGrainMapsToRowAxisOnly(): void
    {
        [$rowAxis, $columnAxis] = $this->mapper->fromLegacyDimension(
            AnalysisDimensionKey::Gender,
            AnalysisDimensionGrain::Total,
        );

        self::assertSame(AnalysisDimensionKey::Gender, $rowAxis->dimensionKey);
        self::assertSame(AnalysisDimensionGrain::Total, $rowAxis->resolvedGrain());
        self::assertNull($columnAxis);
    }
}
