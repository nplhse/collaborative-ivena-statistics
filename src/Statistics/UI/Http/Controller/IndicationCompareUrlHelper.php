<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\IndicationDashboard\IndicationSubject;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectType;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final readonly class IndicationCompareUrlHelper
{
    public function __construct(
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    public function buildDashboardUrl(Request $request, IndicationSubject $subject): string
    {
        return match ($subject->type) {
            IndicationSubjectType::Single => $this->navigationUrlBuilder->build(
                $request,
                'app_stats_indication_dashboard',
                ['indicationId' => $subject->id],
            ),
            IndicationSubjectType::Group => $this->navigationUrlBuilder->build(
                $request,
                'app_stats_indication_group_dashboard',
                ['groupId' => $subject->id],
            ),
        };
    }

    /**
     * @return array<string, int|string>
     */
    public function buildCompareQueryParams(IndicationSubject $subjectA, IndicationSubject $subjectB): array
    {
        return [
            StatisticsQueryKeys::SUBJECT_A_TYPE => $subjectA->type->value,
            StatisticsQueryKeys::SUBJECT_A_ID => $subjectA->id,
            StatisticsQueryKeys::SUBJECT_B_TYPE => $subjectB->type->value,
            StatisticsQueryKeys::SUBJECT_B_ID => $subjectB->id,
        ];
    }
}
