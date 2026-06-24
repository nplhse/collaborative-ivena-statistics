<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\Contract\ExplorerAnalysisQueryMapperInterface;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery as GenericAnalysisQuery;

final readonly class ExplorerAllocationQueryMapper implements ExplorerAnalysisQueryMapperInterface
{
    public function __construct(
        private ExplorerMetricKeyMapper $metricKeyMapper,
    ) {
    }

    #[\Override]
    public function supports(AnalysisQuery $query): bool
    {
        return AnalysisDataSourceKey::Allocations === $query->dataSourceKey;
    }

    #[\Override]
    public function map(AnalysisQuery $query): GenericAnalysisQuery
    {
        $metricKeys = $this->metricKeyMapper->toRegistryKeys($query->metricKeys);
        $visualMetricKey = $query->visualMetricKey->registryKey();

        return new GenericAnalysisQuery(
            primaryDimensionKey: $query->rowAxis->toRegistryKey(),
            scopeCriteria: $query->scopeCriteria,
            periodBounds: $query->periodBounds,
            seriesDimensionKey: $query->columnAxis?->toRegistryKey(),
            metricKeys: $metricKeys,
            visualMetricKey: $visualMetricKey,
            filters: $query->filters,
        );
    }
}
