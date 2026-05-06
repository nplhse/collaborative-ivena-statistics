<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Infrastructure\Repository\AllocationStatsProjectionRepository;

final readonly class ClinicalFeaturesQuery
{
    /** @var list<array{key: string, labelTranslationKey: string}> */
    private const array CLINICAL_FEATURE_DEFINITIONS = [
        ['key' => 'with_physician', 'labelTranslationKey' => 'statistics.distribution.dim.is_with_physician'],
        ['key' => 'cpr', 'labelTranslationKey' => 'statistics.distribution.dim.is_cpr'],
        ['key' => 'ventilated', 'labelTranslationKey' => 'statistics.distribution.dim.is_ventilated'],
        ['key' => 'shock', 'labelTranslationKey' => 'stats.analysis.feature.is_shock'],
        ['key' => 'pregnant', 'labelTranslationKey' => 'stats.analysis.feature.is_pregnant'],
        ['key' => 'infectious', 'labelTranslationKey' => 'field.infection'],
    ];

    /** @var list<array{key: string, labelTranslationKey: string}> */
    private const array RESOURCE_FEATURE_DEFINITIONS = [
        ['key' => 'cathlab_required', 'labelTranslationKey' => 'statistics.distribution.dim.requires_cathlab'],
        ['key' => 'resus_required', 'labelTranslationKey' => 'statistics.distribution.dim.requires_resus'],
    ];

    public function __construct(
        private StatisticsScopeResolver $scopeResolver,
        private AllocationStatsProjectionRepository $projectionRepository,
    ) {
    }

    /**
     * @return list<array{labelTranslationKey: string, count: int, percent: float}>
     */
    public function fetchClinicalRows(StatisticsContext $context): array
    {
        [$totalAllocations, $clinicalCounts] = $this->loadCounts($context);

        $rows = [];
        foreach (self::CLINICAL_FEATURE_DEFINITIONS as $definition) {
            $count = $clinicalCounts[$definition['key']] ?? 0;
            $rows[] = [
                'labelTranslationKey' => $definition['labelTranslationKey'],
                'count' => $count,
                'percent' => $totalAllocations > 0 ? round(($count / $totalAllocations) * 100, 1) : 0.0,
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

        $rows = [];
        foreach (self::RESOURCE_FEATURE_DEFINITIONS as $definition) {
            $count = match ($definition['key']) {
                'cathlab_required' => $resourceCounts['cathlab'] ?? 0,
                'resus_required' => $resourceCounts['resus'] ?? 0,
                default => 0,
            };
            $rows[] = [
                'labelTranslationKey' => $definition['labelTranslationKey'],
                'count' => $count,
                'percent' => $totalAllocations > 0 ? round(($count / $totalAllocations) * 100, 1) : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @return array{0:int,1:array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,infectious:int},2:array{cathlab:int,resus:int}}
     */
    private function loadCounts(StatisticsContext $context): array
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $hospitalIds = $this->scopeResolver->hospitalIdsOrNull($context);
        $totalAllocations = $this->projectionRepository->countCreatedInPeriod($bounds->from, $bounds->toExclusive, $hospitalIds);
        $clinicalCounts = $this->projectionRepository->clinicalFeatureCounts($bounds->from, $bounds->toExclusive, $hospitalIds);
        $resourceCounts = $this->projectionRepository->resourceFeatureCounts($bounds->from, $bounds->toExclusive, $hospitalIds);

        return [$totalAllocations, $clinicalCounts, $resourceCounts];
    }
}
