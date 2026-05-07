<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Infrastructure\Query\ProjectionFeatureQuery;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;

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

    public function __construct(
        private StatisticsScopeResolver $scopeResolver,
        private ProjectionTimeSeriesQuery $timeSeriesQuery,
        private ProjectionFeatureQuery $featureQuery,
    ) {
    }

    /**
     * @return list<array{labelTranslationKey: string, count: int, percent: float}>
     */
    public function fetchClinicalRows(StatisticsContext $context): array
    {
        [$totalAllocations, $clinicalCounts] = $this->loadCounts($context);
        $totalAllocationsFloat = (float) $totalAllocations;

        $rows = [];
        foreach (self::CLINICAL_FEATURE_DEFINITIONS as $definition) {
            $count = $clinicalCounts[$definition['key']] ?? 0;
            $rows[] = [
                'labelTranslationKey' => $definition['labelTranslationKey'],
                'count' => $count,
                'percent' => $totalAllocations > 0 ? round(((float) $count / $totalAllocationsFloat) * 100.0, 1) : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{labelTranslationKey: string, count: int, percent: float}>
     */
    public function fetchResourceRows(StatisticsContext $context): array
    {
        [$totalAllocations, , $resourceCounts] = $this->loadCounts($context);
        $totalAllocationsFloat = (float) $totalAllocations;
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
                'percent' => $totalAllocations > 0 ? round(((float) $count / $totalAllocationsFloat) * 100.0, 1) : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @return array{0:int,1:array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,work_accident:int,infectious:int},2:array{cathlab:int,resus:int}}
     */
    private function loadCounts(StatisticsContext $context): array
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $scopeCriteria = $this->scopeResolver->resolveCriteria($context);
        $hospitalIds = $scopeCriteria->hospitalIds;
        $totalAllocations = $this->timeSeriesQuery->countCreatedInPeriod($bounds->from, $bounds->toExclusive, $hospitalIds);
        $clinicalCounts = $this->featureQuery->clinicalFeatureCounts($bounds->from, $bounds->toExclusive, $hospitalIds);
        $resourceCounts = $this->featureQuery->resourceFeatureCounts($bounds->from, $bounds->toExclusive, $hospitalIds);

        return [$totalAllocations, $clinicalCounts, $resourceCounts];
    }
}
