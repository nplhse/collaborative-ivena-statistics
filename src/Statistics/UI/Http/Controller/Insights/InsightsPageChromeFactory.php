<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller\Insights;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\UI\Http\Controller\OverviewPeriodViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsDataQualityReportFactory;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final readonly class InsightsPageChromeFactory
{
    public function __construct(
        private StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private StatisticsDataQualityReportFactory $dataQualityReportFactory,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function templateVars(
        Request $request,
        string $routeName,
        ?User $user,
        StatisticsFilter $filter,
        ?int $indicationId = null,
    ): array {
        $pageViewModel = $this->statisticsPageViewModelFactory->create($request, $routeName, $user, $filter);
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create($request, $routeName, $filter);
        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
            $indicationId,
        );

        return [
            'dataQualityReport' => $dataQualityReport,
            'statisticsFilter' => $pageViewModel->filter,
            'statsScopeUrls' => $pageViewModel->scopeUrls,
            'statsHospitalUrls' => $pageViewModel->hospitalUrls,
            'cohortScopeChoices' => $pageViewModel->cohortScopeChoices,
            'statsCohortDropdownSelectedName' => $pageViewModel->cohortDropdownSelectedName,
            'statsScopePrimaryMenu' => $pageViewModel->scopePrimaryMenu,
            'statsScopeSecondaryMenu' => $pageViewModel->scopeSecondaryMenu,
            'statsShowScopeSecondaryPicker' => $pageViewModel->showScopeSecondaryPicker,
            'statsScopePrimaryDropdownLabel' => $pageViewModel->scopePrimaryDropdownLabel,
            'statsScopeSecondaryDropdownLabel' => $pageViewModel->scopeSecondaryDropdownLabel,
            'statsPeriodUrls' => $pageViewModel->periodUrls,
            'accessibleHospitals' => $pageViewModel->accessibleHospitals,
            'statsHospitalDropdownSelectedName' => $pageViewModel->hospitalDropdownSelectedName,
            'isLoggedIn' => $pageViewModel->isLoggedIn,
            'statisticsHeadingScope' => $pageViewModel->headingScope,
            'statisticsHeadingPeriod' => $overviewPeriodViewModel->headingLabel,
            'overviewPeriodViewModel' => $overviewPeriodViewModel,
            'statsUseOverviewPeriodControls' => true,
        ];
    }
}
