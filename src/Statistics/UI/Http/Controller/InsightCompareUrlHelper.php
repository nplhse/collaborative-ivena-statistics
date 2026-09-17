<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\Insights\InsightSubject;
use App\Statistics\Benchmarking\Application\BenchmarkSelectionQueryBuilder;
use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionFormDataFactory;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionFormData;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final readonly class InsightCompareUrlHelper
{
    public function __construct(
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
        private BenchmarkSelectionQueryBuilder $selectionQueryBuilder,
        private BenchmarkSelectionFormDataFactory $selectionFormDataFactory,
    ) {
    }

    public function buildDashboardUrl(Request $request, InsightSubject $subject): string
    {
        return $this->navigationUrlBuilder->build(
            $request,
            'app_stats_insights_show',
            [
                'dimension' => $subject->dimension->value,
                'id' => $subject->id,
            ],
            [
                ...StatisticsQueryKeys::INSIGHT_COMPARE_SUBJECT_KEYS,
                ...StatisticsQueryKeys::COMPARISON_FILTERS,
                'q',
                'sort',
                'page',
                'limit',
                'view',
            ],
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function buildCompareQueryParams(InsightSubject $subjectA, InsightSubject $subjectB): array
    {
        return [
            StatisticsQueryKeys::SUBJECT_A_DIMENSION => $subjectA->dimension->value,
            StatisticsQueryKeys::SUBJECT_A_ID => $subjectA->id,
            StatisticsQueryKeys::SUBJECT_B_DIMENSION => $subjectB->dimension->value,
            StatisticsQueryKeys::SUBJECT_B_ID => $subjectB->id,
        ];
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $replace
     * @param list<string>                            $removeKeys
     */
    public function buildCompareUrl(
        Request $request,
        InsightSubject $subjectA,
        InsightSubject $subjectB,
        array $replace = [],
        array $removeKeys = [],
    ): string {
        return $this->navigationUrlBuilder->build(
            $request,
            'app_stats_insights_compare',
            array_merge($this->buildCompareQueryParams($subjectA, $subjectB), $replace),
            [
                'dimension',
                'id',
                'q',
                'sort',
                'page',
                'limit',
                'view',
                ...$removeKeys,
            ],
        );
    }

    public function buildSwapUrl(
        Request $request,
        InsightSubject $subjectA,
        InsightSubject $subjectB,
        StatisticsFilter $primaryFilter,
        StatisticsFilter $comparisonFilter,
    ): string {
        $query = $request->query->all();
        foreach (array_merge(StatisticsQueryKeys::PRIMARY_FILTERS, StatisticsQueryKeys::COMPARISON_FILTERS, StatisticsQueryKeys::INSIGHT_COMPARE_SUBJECT_KEYS, ['dimension', 'id']) as $key) {
            unset($query[$key]);
        }
        $hadComparisonQuery = array_any(StatisticsQueryKeys::COMPARISON_FILTERS, fn (string $key): bool => '' !== trim((string) $request->query->get($key)));

        if (self::filtersEqual($primaryFilter, $comparisonFilter) && !$hadComparisonQuery) {
            return $this->navigationUrlBuilder->generate(
                'app_stats_insights_compare',
                array_merge(
                    $query,
                    $this->selectionQueryBuilder->sideFilterParams(
                        $this->selectionFormDataFactory->fromFilter($primaryFilter),
                        false,
                    ),
                    $this->buildCompareQueryParams($subjectB, $subjectA),
                ),
            );
        }

        $swappedFilters = $this->selectionQueryBuilder->build(
            new BenchmarkSelectionFormData(
                $this->selectionFormDataFactory->fromFilter($comparisonFilter),
                $this->selectionFormDataFactory->fromFilter($primaryFilter),
            ),
            [],
        );
        unset($swappedFilters[StatisticsQueryKeys::COMPARE]);

        return $this->navigationUrlBuilder->generate(
            'app_stats_insights_compare',
            array_merge(
                $query,
                $swappedFilters,
                $this->buildCompareQueryParams($subjectB, $subjectA),
            ),
        );
    }

    /**
     * @return array<string, bool|float|int|string>
     */
    public function preservedQuery(Request $request): array
    {
        $query = $request->query->all();
        unset($query['dimension'], $query['id'], $query['q'], $query['sort'], $query['page'], $query['limit'], $query['view']);

        return $query;
    }

    public static function filtersEqual(StatisticsFilter $a, StatisticsFilter $b): bool
    {
        return $a->scope === $b->scope
            && $a->hospitalId === $b->hospitalId
            && ($a->cohortType?->value() ?? '') === ($b->cohortType?->value() ?? '')
            && $a->period === $b->period
            && $a->referenceYear === $b->referenceYear
            && $a->referenceMonth === $b->referenceMonth
            && $a->referenceQuarter === $b->referenceQuarter
            && $a->stateId === $b->stateId
            && $a->dispatchAreaId === $b->dispatchAreaId;
    }
}
