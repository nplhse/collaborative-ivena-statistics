<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery as GenericAnalysisQuery;

final readonly class ExplorerAllocationQueryMapper
{
    public function __construct(
        private ExplorerMetricKeyMapper $metricKeyMapper,
    ) {
    }

    public function map(AnalysisQuery $query): GenericAnalysisQuery
    {
        $grain = $query->timeGrain ?? AnalysisDimensionGrain::Month;
        $metricKeys = $this->metricKeyMapper->toRegistryKeys($query->metricKeys);
        $visualMetricKey = $query->visualMetricKey->registryKey();

        if ($query->dimensionKey->isTemporalPrimary()) {
            return new GenericAnalysisQuery(
                primaryDimensionKey: $grain->registryTemporalKey(),
                scopeCriteria: $query->scopeCriteria,
                periodBounds: $query->periodBounds,
                metricKeys: $metricKeys,
                visualMetricKey: $visualMetricKey,
            );
        }

        if ($grain->isTemporal()) {
            return new GenericAnalysisQuery(
                primaryDimensionKey: $grain->registryTemporalKey(),
                scopeCriteria: $query->scopeCriteria,
                periodBounds: $query->periodBounds,
                seriesDimensionKey: $query->dimensionKey->registryKey(),
                metricKeys: $metricKeys,
                visualMetricKey: $visualMetricKey,
            );
        }

        return new GenericAnalysisQuery(
            primaryDimensionKey: $query->dimensionKey->registryKey(),
            scopeCriteria: $query->scopeCriteria,
            periodBounds: $query->periodBounds,
            metricKeys: $metricKeys,
            visualMetricKey: $visualMetricKey,
        );
    }
}
