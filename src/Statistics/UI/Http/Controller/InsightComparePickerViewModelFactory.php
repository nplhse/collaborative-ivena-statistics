<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\Insights\InsightSubject;
use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionFormDataFactory;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final readonly class InsightComparePickerViewModelFactory
{
    public function __construct(
        private InsightCompareUrlHelper $compareUrlHelper,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
        private BenchmarkSelectionFormDataFactory $selectionFormDataFactory,
    ) {
    }

    public function create(
        Request $request,
        InsightSubject $subjectA,
        ?InsightSubject $subjectB,
        StatisticsFilter $comparisonFilter,
        string $referenceLabelA,
        string $referenceDetailA,
    ): InsightComparePickerViewModel {
        $searchUrl = $this->navigationUrlBuilder->build(
            $request,
            'app_stats_insights_search',
            [],
            [
                'dimension',
                'id',
                'q',
                'sort',
                'page',
                'limit',
                'view',
                ...StatisticsQueryKeys::INSIGHT_COMPARE_SUBJECT_KEYS,
            ],
        );
        $removeB = $subjectB instanceof InsightSubject
            ? []
            : [
                StatisticsQueryKeys::SUBJECT_B_DIMENSION,
                StatisticsQueryKeys::SUBJECT_B_ID,
            ];

        return new InsightComparePickerViewModel(
            $subjectA,
            $subjectB,
            $searchUrl,
            $this->compareUrlHelper->buildCompareUrl($request, $subjectA, $subjectB ?? $subjectA, [], $removeB),
            $this->selectionFormDataFactory->fromFilter($comparisonFilter),
            $this->compareUrlHelper->preservedQuery($request),
            $referenceLabelA,
            $referenceDetailA,
        );
    }
}
