<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\ClinicalFeaturesProvider;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\HospitalSummaryProvider;
use App\Statistics\Application\OverviewDashboardProvider;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Infrastructure\Query\Overview\GetOverviewDashboardMetricsQuery;
use App\Statistics\Infrastructure\Query\Overview\OverviewQueryCriteria;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly OverviewDashboardProvider $overviewDashboardProvider,
        private readonly HospitalSummaryProvider $hospitalSummaryProvider,
        private readonly ClinicalFeaturesProvider $clinicalFeaturesProvider,
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly StatisticsExplorerViewModelFactory $statisticsExplorerViewModelFactory,
        private readonly StatisticsFilterDrawerStateFactory $statisticsFilterDrawerStateFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly GetOverviewDashboardMetricsQuery $overviewDashboardMetricsQuery,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
    ) {
    }

    #[Route('/statistics/', name: 'app_stats_dashboard', methods: ['GET'])]
    public function index(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', $publicRedirect['notice']->value);
            }

            return $this->redirectToRoute('app_stats_dashboard', $publicRedirect['query']);
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $overviewMetrics = ($this->overviewDashboardMetricsQuery)(OverviewQueryCriteria::fromPeriodBounds(
            StatisticsPeriodResolver::resolve($filter),
            $this->statisticsScopeResolver->resolveCriteria($context)->hospitalIds,
        ));
        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_dashboard',
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create($request, 'app_stats_dashboard', $filter);

        if ($pageViewModel->showUnscopedHint) {
            $this->addFlash('info', 'stats.overview.hospital_summary.unscoped_hint');
        }
        $drawerState = $this->statisticsFilterDrawerStateFactory->fromRequest($request);
        $chartPairWidget = array_find($this->overviewDashboardProvider->build($context), fn ($widget): bool => StatisticWidgetType::ChartPair === $widget->type);

        return $this->render('@Statistics/dashboard/index.html.twig', [
            'chartPairWidget' => $chartPairWidget,
            'hospitalSummaryWidgets' => $this->hospitalSummaryProvider->build($context, $overviewMetrics),
            'clinicalFeatureWidgets' => $this->clinicalFeaturesProvider->build($context, $overviewMetrics),
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
            'statsExplorerSections' => $this->statisticsExplorerViewModelFactory->create($request, 'dashboard'),
            'statsFilterDrawerValues' => $drawerState['values'],
            'statsActiveFilterCount' => $drawerState['activeCount'],
            'statsFilterDrawerResetUrl' => $this->generateUrl('app_stats_dashboard'),
        ]);
    }
}
