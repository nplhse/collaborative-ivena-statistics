<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewDashboardMetricsResult;

final readonly class ClinicalFeaturesQuery
{
    /** @var list<array{key: string, labelTranslationKey: string}> */
    private const array CLINICAL_FEATURE_DEFINITIONS = [
        ['key' => 'with_physician', 'labelTranslationKey' => 'statistics.distribution.dim.is_with_physician'],
        ['key' => 'cpr', 'labelTranslationKey' => 'statistics.distribution.dim.is_cpr'],
        ['key' => 'ventilated', 'labelTranslationKey' => 'statistics.distribution.dim.is_ventilated'],
        ['key' => 'shock', 'labelTranslationKey' => 'stats.analysis.feature.is_shock'],
        ['key' => 'pregnant', 'labelTranslationKey' => 'stats.analysis.feature.is_pregnant'],
        ['key' => 'work_accident', 'labelTranslationKey' => 'stats.analysis.feature.is_work_accident'],
        ['key' => 'infectious', 'labelTranslationKey' => 'field.infection'],
    ];

    /** @var list<array{key: string, labelTranslationKey: string}> */
    private const array RESOURCE_FEATURE_DEFINITIONS = [
        ['key' => 'cathlab_required', 'labelTranslationKey' => 'statistics.distribution.dim.requires_cathlab'],
        ['key' => 'resus_required', 'labelTranslationKey' => 'statistics.distribution.dim.requires_resus'],
    ];

    /**
     * @return list<array{labelTranslationKey: string, count: int, percent: float}>
     */
    public function fetchClinicalRows(OverviewDashboardMetricsResult $metrics): array
    {
        $clinicalCounts = $metrics->clinicalCounts();
        $totalAllocationsFloat = (float) $metrics->scopedTotal;

        $rows = [];
        foreach (self::CLINICAL_FEATURE_DEFINITIONS as $definition) {
            $count = $clinicalCounts[$definition['key']] ?? 0;
            $rows[] = [
                'labelTranslationKey' => $definition['labelTranslationKey'],
                'count' => $count,
                'percent' => $metrics->scopedTotal > 0 ? round(((float) $count / $totalAllocationsFloat) * 100.0, 1) : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{labelTranslationKey: string, count: int, percent: float}>
     */
    public function fetchResourceRows(OverviewDashboardMetricsResult $metrics): array
    {
        $resourceCounts = $metrics->resourceCounts();
        $totalAllocationsFloat = (float) $metrics->scopedTotal;
        $resourceCountByDefinitionKey = [
            'cathlab_required' => $resourceCounts['cathlab'] ?? 0,
            'resus_required' => $resourceCounts['resus'] ?? 0,
        ];

        $rows = [];
        foreach (self::RESOURCE_FEATURE_DEFINITIONS as $definition) {
            $count = $resourceCountByDefinitionKey[$definition['key']];
            $rows[] = [
                'labelTranslationKey' => $definition['labelTranslationKey'],
                'count' => $count,
                'percent' => $metrics->scopedTotal > 0 ? round(((float) $count / $totalAllocationsFloat) * 100.0, 1) : 0.0,
            ];
        }

        return $rows;
    }
}
