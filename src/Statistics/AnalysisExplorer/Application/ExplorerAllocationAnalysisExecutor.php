<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\GenericAnalysis\Application\MetricCompatibilityChecker;
use App\Statistics\GenericAnalysis\Application\RelativeDistributionCalculator;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisQuery;

final readonly class ExplorerAllocationAnalysisExecutor
{
    public function __construct(
        private ExplorerQueryMapperRegistry $queryMapperRegistry,
        private GenericAllocationAnalysisQuery $genericQuery,
        private MetricCompatibilityChecker $metricCompatibilityChecker,
        private RelativeDistributionCalculator $relativeDistributionCalculator,
        private ExplorerAllocationResultMapper $resultMapper,
    ) {
    }

    /**
     * @return list<AnalysisResultRow>
     */
    public function execute(AnalysisQuery $query): array
    {
        if ($this->hasEmptyHospitalScope($query)) {
            return [];
        }

        $gaQuery = $this->queryMapperRegistry->map($query);
        $this->metricCompatibilityChecker->resolveAndValidate($gaQuery);
        $raw = $this->genericQuery->execute($gaQuery);
        $enriched = $this->relativeDistributionCalculator->enrich($raw, $gaQuery->resolvedMetricKeys());

        return $this->resultMapper->map($raw, $enriched, $query);
    }

    private function hasEmptyHospitalScope(AnalysisQuery $query): bool
    {
        $hospitalIds = $query->scopeCriteria->hospitalIds;

        return \is_array($hospitalIds) && [] === $hospitalIds;
    }
}
