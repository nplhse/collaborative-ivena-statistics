<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\CaseFlow\Application\CaseFlowCriteriaFactory;
use App\Statistics\CaseFlow\Application\CaseFlowDashboardService;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegment;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentCatalogFactory;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentProfileDimension;
use App\Statistics\UI\Http\Controller\AnalysisContextViewModelFactory;
use App\Statistics\UI\Http\Controller\OverviewPeriodViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsDataQualityReportFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterDrawerViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsPublicScopeRedirector;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Translation\TranslatableMessage;

final class CaseFlowController extends AbstractController
{
    public function __construct(
        private readonly CaseFlowDashboardService $dashboardService,
        private readonly CaseFlowCriteriaFactory $criteriaFactory,
        private readonly GeographicSegmentCatalogFactory $segmentCatalogFactory,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly CaseFlowChartPayloadFactory $chartPayloadFactory,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
        private readonly StatisticsFilterDrawerViewModelFactory $statisticsFilterDrawerViewModelFactory,
        private readonly AnalysisContextViewModelFactory $analysisContextViewModelFactory,
    ) {
    }

    #[Route('/statistics/case-flow', name: 'app_stats_case_flow', methods: ['GET'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', new TranslatableMessage($publicRedirect['notice']->value, domain: 'statistics'));
            }

            return $this->redirectToRoute('app_stats_case_flow', $publicRedirect['query']);
        }

        $criteria = $this->criteriaFactory->create($user, $filter, $request);
        $result = $this->dashboardService->build($criteria);
        $catalog = $this->segmentCatalogFactory->fromDashboardResult($result);
        $selectedSegment = GeographicSegment::tryFromQueryValue($request->query->getString(StatisticsQueryKeys::GEO_SEGMENT));
        $profileDimension = GeographicSegmentProfileDimension::fromQueryValue(
            $request->query->getString(StatisticsQueryKeys::GEO_PROFILE),
        );

        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_case_flow',
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            'app_stats_case_flow',
            $filter,
        );
        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
        );

        return $this->render('@Statistics/case_flow/index.html.twig', [
            'dataQualityReport' => $dataQualityReport,
            'dashboard' => $result,
            'chartPayload' => $this->chartPayloadFactory->create($result, $selectedSegment),
            'segmentCatalog' => $catalog,
            'selectedSegment' => $selectedSegment,
            'segmentProfileDimension' => $profileDimension,
            'segmentProfileDimensions' => GeographicSegmentProfileDimension::cases(),
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
            'statsShowFilterDrawer' => true,
            'statsFilterDrawer' => $this->statisticsFilterDrawerViewModelFactory->create($request),
            'statsAnalysisContext' => $this->analysisContextViewModelFactory->create(
                $request,
                'app_stats_case_flow',
                $user,
                $filter,
                $pageViewModel->headingScope,
                $overviewPeriodViewModel->headingLabel,
            ),
        ]);
    }
}
