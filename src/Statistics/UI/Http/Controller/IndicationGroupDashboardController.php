<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\IndicationDashboard\IndicationDashboardService;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectResolver;
use App\Statistics\Application\IndicationGroup\IndicationGroupMemberCompareService;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class IndicationGroupDashboardController extends AbstractController
{
    public function __construct(
        private readonly IndicationSubjectResolver $subjectResolver,
        private readonly IndicationDashboardService $dashboardService,
        private readonly IndicationGroupMemberCompareService $memberCompareService,
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly StatisticsFilterDrawerStateFactory $statisticsFilterDrawerStateFactory,
        private readonly IndicationDashboardChartPayloadFactory $chartPayloadFactory,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
        private readonly IndicationGroupPickerViewModelFactory $groupPickerViewModelFactory,
        private readonly IndicationGroupComparePickerViewModelFactory $groupComparePickerViewModelFactory,
        private readonly IndicationComparePickerViewModelFactory $comparePickerViewModelFactory,
        private readonly StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    #[Route('/statistics/indication-group/{groupId}', name: 'app_stats_indication_group_dashboard', requirements: ['groupId' => '\d+'], methods: ['GET'])]
    public function show(
        Request $request,
        int $groupId,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', $publicRedirect['notice']->value);
            }

            $redirectParams = array_merge($publicRedirect['query'], ['groupId' => $groupId]);

            return $this->redirectToRoute('app_stats_indication_group_dashboard', $redirectParams);
        }

        $subject = $this->subjectResolver->resolveGroup($groupId);
        if (!$subject instanceof \App\Statistics\Application\IndicationDashboard\IndicationSubject) {
            throw $this->createNotFoundException('Indication group not found.');
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $scope = $this->statisticsScopeResolver->resolveCriteria($context);
        $period = StatisticsPeriodResolver::resolve($filter);

        $result = $this->dashboardService->buildForSubject($subject, $scope, $period);
        if (!$result instanceof \App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardResult) {
            return $this->render('@Statistics/indication_group/empty.html.twig', [
                'groupName' => $subject->label,
            ]);
        }

        $memberRows = $this->memberCompareService->buildMemberRows($subject, $scope, $period);
        $compareMemberRows = $this->memberCompareService->buildComparePickerRows($subject, $memberRows);
        $memberRows = array_map(function (array $row) use ($request): array {
            $row['insightUrl'] = $this->navigationUrlBuilder->build($request, 'app_stats_indication_dashboard', [
                'indicationId' => $row['indicationId'],
            ]);

            return $row;
        }, $memberRows);
        $comparePresets = \count($compareMemberRows) >= 2
            ? $this->groupComparePickerViewModelFactory->createPresets($compareMemberRows)
            : [];

        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_indication_group_dashboard',
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            'app_stats_indication_group_dashboard',
            $filter,
        );
        $drawerState = $this->statisticsFilterDrawerStateFactory->fromRequest($request);

        return $this->render('@Statistics/indication_group/index.html.twig', [
            'dashboard' => $result,
            'memberRows' => $memberRows,
            'groupId' => $groupId,
            'groupPicker' => $this->groupPickerViewModelFactory->create($request, $groupId),
            'chartPayload' => $this->chartPayloadFactory->create($result),
            'dataQualityReport' => $this->dataQualityReportFactory->create($filter, $user, $pageViewModel, $overviewPeriodViewModel),
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
            'statsFilterDrawerResetUrl' => $this->generateUrl('app_stats_indication_group_dashboard', ['groupId' => $groupId]),
            'comparePicker' => $this->comparePickerViewModelFactory->create($request, $subject),
            'comparePresets' => $comparePresets,
            'statsShowCompareLaunchButton' => true,
            'statsCompareLaunchModalId' => 'stats-indication-group-compare-launch-modal',
        ]);
    }
}
