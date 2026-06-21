<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisMatrix;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisTotals;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use PHPUnit\Framework\TestCase;

final class AnalysisMatrixTest extends TestCase
{
    public function testFromRunResultBuildsPivotCells(): void
    {
        $result = new AnalysisRunResult(
            title: 'Test',
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            rows: [
                new AnalysisResultRow('2024-06', 'Jun 2024', '1', 'Male', ['allocation_count' => 4]),
                new AnalysisResultRow('2024-06', 'Jun 2024', '2', 'Female', ['allocation_count' => 6]),
                new AnalysisResultRow('2024-07', 'Jul 2024', '1', 'Male', ['allocation_count' => 3]),
            ],
            totals: new AnalysisTotals(grand: ['allocation_count' => 13.0]),
        );

        $matrix = AnalysisMatrix::fromRunResult($result);

        self::assertTrue($matrix->hasColumnAxis());
        self::assertSame(['2024-06', '2024-07'], $matrix->orderedRowKeys);
        self::assertSame(['1', '2'], $matrix->orderedColumnKeys);
        self::assertSame(4.0, $matrix->valueFor('2024-06', '1', AnalysisMetricKey::AllocationCount));
        self::assertSame(6.0, $matrix->valueFor('2024-06', '2', AnalysisMetricKey::AllocationCount));
    }

    public function testChartSeriesWithoutColumnAxis(): void
    {
        $result = new AnalysisRunResult(
            title: 'Test',
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: null,
            rows: [
                new AnalysisResultRow('2024-06', 'Jun 2024', null, null, ['allocation_count' => 5]),
                new AnalysisResultRow('2024-07', 'Jul 2024', null, null, ['allocation_count' => 8]),
            ],
            totals: new AnalysisTotals(grand: ['allocation_count' => 13.0]),
        );

        $matrix = AnalysisMatrix::fromRunResult($result);
        $series = $matrix->chartSeries(AnalysisMetricKey::AllocationCount);

        self::assertFalse($matrix->hasColumnAxis());
        self::assertSame([['name' => '', 'data' => [5.0, 8.0]]], $series);
        self::assertSame(['Jun 2024', 'Jul 2024'], $matrix->chartLabels());
    }
}
