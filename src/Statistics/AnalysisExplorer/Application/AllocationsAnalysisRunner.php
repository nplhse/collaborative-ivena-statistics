<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\Contract\AnalysisRunnerInterface;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Infrastructure\Query\AllocationsTimeSeriesCountQuery;

final readonly class AllocationsAnalysisRunner implements AnalysisRunnerInterface
{
    public static function capabilities(): DataSourceCapabilities
    {
        return new DataSourceCapabilities(
            key: AnalysisDataSourceKey::Allocations,
            supportedMetrics: [AnalysisMetricKey::AllocationCount],
            supportedDimensions: [AnalysisDimensionGrain::Month, AnalysisDimensionGrain::Year],
            defaultMetric: AnalysisMetricKey::AllocationCount,
            defaultDimension: AnalysisDimensionGrain::Month,
        );
    }

    public function __construct(
        private AllocationsTimeSeriesCountQuery $timeSeriesCountQuery,
    ) {
    }

    #[\Override]
    public function supports(AnalysisViewConfig $config): bool
    {
        $capabilities = self::capabilities();

        if (!\in_array($config->metricKey, $capabilities->supportedMetrics, true)) {
            return false;
        }

        return \in_array($config->dimensionGrain, $capabilities->supportedDimensions, true);
    }

    #[\Override]
    public function run(AnalysisQuery $query): AnalysisRunResult
    {
        $dataPoints = $this->timeSeriesCountQuery->execute($query);
        $total = 0;

        foreach ($dataPoints as $point) {
            $total += $point->value;
        }

        return new AnalysisRunResult(
            title: '',
            metricKey: $query->metricKey,
            dimensionGrain: $query->dimensionGrain,
            dataPoints: $dataPoints,
            total: $total,
        );
    }
}
