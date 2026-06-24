<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\Mapping\ClinicalIndicatorDefinitions;
use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewDashboardMetricsResult;

final readonly class ClinicalFeaturesQuery
{
    /**
     * @return list<array{labelTranslationKey: string, count: int, percent: float}>
     */
    public function fetchClinicalRows(OverviewDashboardMetricsResult $metrics): array
    {
        $clinicalCounts = $metrics->clinicalCounts();
        $totalAllocationsFloat = (float) $metrics->scopedTotal;

        return $this->buildRows(
            ClinicalIndicatorDefinitions::forDimension(ClinicalIndicatorDefinitions::DIMENSION_FEATURES),
            $clinicalCounts,
            $totalAllocationsFloat,
            $metrics->scopedTotal,
        );
    }

    /**
     * @return list<array{labelTranslationKey: string, count: int, percent: float}>
     */
    public function fetchResourceRows(OverviewDashboardMetricsResult $metrics): array
    {
        $resourceCounts = $metrics->resourceCounts();
        $totalAllocationsFloat = (float) $metrics->scopedTotal;

        return $this->buildRows(
            ClinicalIndicatorDefinitions::forDimension(ClinicalIndicatorDefinitions::DIMENSION_RESOURCES),
            $resourceCounts,
            $totalAllocationsFloat,
            $metrics->scopedTotal,
        );
    }

    /**
     * @param list<Mapping\ClinicalIndicatorDefinition> $definitions
     * @param array<string, int>                        $countsByOverviewKey
     *
     * @return list<array{labelTranslationKey: string, count: int, percent: float}>
     */
    private function buildRows(
        array $definitions,
        array $countsByOverviewKey,
        float $totalAllocationsFloat,
        int $scopedTotal,
    ): array {
        $rows = [];
        foreach ($definitions as $definition) {
            $count = $countsByOverviewKey[$definition->overviewCountKey] ?? 0;
            $rows[] = [
                'labelTranslationKey' => $definition->labelTranslationKey,
                'count' => $count,
                'percent' => $scopedTotal > 0 ? round((float) $count / $totalAllocationsFloat * 100.0, 1) : 0.0,
            ];
        }

        return $rows;
    }
}
