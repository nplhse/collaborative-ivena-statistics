<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardCriteria;
use App\Statistics\Application\IndicationDashboard\IndicationDashboardService;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class IndicationDashboardController extends AbstractController
{
    public function __construct(
        private readonly IndicationDashboardService $indicationDashboardService,
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly StatisticsFilterDrawerStateFactory $statisticsFilterDrawerStateFactory,
        private readonly IndicationDashboardChartPayloadFactory $chartPayloadFactory,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
        private readonly IndicationComparePickerViewModelFactory $comparePickerViewModelFactory,
    ) {
    }

    #[Route('/statistics/indication/{indicationId}', name: 'app_stats_indication_dashboard', requirements: ['indicationId' => '\d+'], methods: ['GET'])]
    public function show(
        Request $request,
        int $indicationId,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', $publicRedirect['notice']->value);
            }

            $redirectParams = array_merge($publicRedirect['query'], ['indicationId' => $indicationId]);

            return $this->redirectToRoute('app_stats_indication_dashboard', $redirectParams);
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $scope = $this->statisticsScopeResolver->resolveCriteria($context);
        $period = StatisticsPeriodResolver::resolve($filter);

        $result = $this->indicationDashboardService->build(new IndicationDashboardCriteria(
            $indicationId,
            $scope,
            $period,
        ));

        if (!$result instanceof \App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardResult) {
            throw $this->createNotFoundException('Indication not found.');
        }

        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_indication_dashboard',
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            'app_stats_indication_dashboard',
            $filter,
        );
        $drawerState = $this->statisticsFilterDrawerStateFactory->fromRequest($request);

        $routeParams = ['indicationId' => $indicationId];

        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
            $indicationId,
        );

        return $this->render('@Statistics/indication_dashboard/index.html.twig', [
            'dashboard' => $result,
            'dataQualityReport' => $dataQualityReport,
            'chartPayload' => $this->chartPayloadFactory->create($result),
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
            'statsFilterDrawerValues' => $drawerState['values'],
            'statsActiveFilterCount' => $drawerState['activeCount'],
            'statsFilterDrawerResetUrl' => $this->generateUrl('app_stats_indication_dashboard', $routeParams),
            'indicationId' => $indicationId,
            'comparePicker' => $this->comparePickerViewModelFactory->create($request, $indicationId),
            'statsShowCompareLaunchButton' => true,
        ]);
    }
}
