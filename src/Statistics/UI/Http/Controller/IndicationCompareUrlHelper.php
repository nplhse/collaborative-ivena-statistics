<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightSubject;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final readonly class IndicationCompareUrlHelper
{
    public function __construct(
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
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
            ['q', 'sort', 'page', 'limit', 'view'],
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function buildCompareQueryParams(InsightSubject $subjectA, InsightSubject $subjectB): array
    {
        return [
            StatisticsQueryKeys::SUBJECT_A_TYPE => $this->subjectType($subjectA),
            StatisticsQueryKeys::SUBJECT_A_ID => $subjectA->id,
            StatisticsQueryKeys::SUBJECT_B_TYPE => $this->subjectType($subjectB),
            StatisticsQueryKeys::SUBJECT_B_ID => $subjectB->id,
        ];
    }

    private function subjectType(InsightSubject $subject): string
    {
        return InsightDimensionKey::IndicationGroups === $subject->dimension
            ? 'group'
            : 'single';
    }
}
