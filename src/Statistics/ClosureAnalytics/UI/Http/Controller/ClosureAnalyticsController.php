<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\ClosureAnalytics\Application\ClosureAnalyticsCriteriaFactory;
use App\Statistics\ClosureAnalytics\Application\ClosureAnalyticsService;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureEventQuery;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureIntervalDetailQuery;
use App\Statistics\UI\Http\Controller\AnalysisContextViewModelFactory;
use App\Statistics\UI\Http\Controller\OverviewPeriodViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsPublicScopeRedirector;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ClosureAnalyticsController extends AbstractController
{
    public function __construct(
        private readonly ClosureAnalyticsService $service,
        private readonly ClosureIntervalDetailQuery $intervalQuery,
        private readonly ClosureEventQuery $eventQuery,
        private readonly ClosureAnalyticsChartPayloadFactory $chartPayloadFactory,
        private readonly ClosureAnalyticsFilterViewModelFactory $filterViewModelFactory,
        private readonly ClosureAnalyticsCriteriaFactory $criteriaFactory,
        private readonly StatisticsPageViewModelFactory $pageViewModelFactory,
        private readonly OverviewPeriodViewModelFactory $periodViewModelFactory,
        private readonly AnalysisContextViewModelFactory $analysisContextFactory,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
    ) {
    }

    #[Route('/statistics/closure-analytics', name: 'app_stats_closure_analytics', methods: ['GET'])]
    public function index(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $redirect = $this->redirectUnsupportedScope($request, $filter, 'app_stats_closure_analytics');
        if ($redirect instanceof Response) {
            return $redirect;
        }
        $dashboard = $this->service->buildOverview(
            $this->criteriaFactory->create(
                $user,
                $filter,
                ClosureAnalyticsFilterRequestResolver::fromRequest($request),
            ),
        );

        return $this->render('@Statistics/closure_analytics/index.html.twig', [
            ...$this->pageVariables($request, $user, $filter, 'app_stats_closure_analytics', 'overview'),
            'dashboard' => $dashboard,
            'chartPayload' => $this->chartPayloadFactory->dashboard($dashboard->timeSeries, $dashboard->heatmap),
        ]);
    }

    #[Route('/statistics/closure-analytics/timeline', name: 'app_stats_closure_analytics_timeline', methods: ['GET'])]
    public function timeline(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $redirect = $this->redirectUnsupportedScope($request, $filter, 'app_stats_closure_analytics_timeline');
        if ($redirect instanceof Response) {
            return $redirect;
        }

        return $this->render('@Statistics/closure_analytics/timeline.html.twig', [
            ...$this->pageVariables($request, $user, $filter, 'app_stats_closure_analytics_timeline', 'timeline'),
            'timelineFrameUrl' => $this->generateUrl(
                'app_stats_closure_analytics_timeline_frame',
                $request->query->all(),
            ),
        ]);
    }

    #[Route('/statistics/closure-analytics/events', name: 'app_stats_closure_analytics_events', methods: ['GET'])]
    public function events(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $redirect = $this->redirectUnsupportedScope($request, $filter, 'app_stats_closure_analytics_events');
        if ($redirect instanceof Response) {
            return $redirect;
        }

        return $this->render('@Statistics/closure_analytics/events.html.twig', [
            ...$this->pageVariables($request, $user, $filter, 'app_stats_closure_analytics_events', 'events'),
            'detailsFrameUrl' => $this->generateUrl(
                'app_stats_closure_analytics_details',
                $request->query->all(),
            ),
        ]);
    }

    #[Route('/statistics/closure-analytics/events/{eventKey}', name: 'app_stats_closure_analytics_event', requirements: ['eventKey' => \Symfony\Component\Routing\Requirement\Requirement::CATCH_ALL], methods: ['GET'])]
    public function event(
        string $eventKey,
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        if (StatisticsFilterScope::DispatchArea === $filter->scope) {
            throw $this->createNotFoundException();
        }

        $criteria = $this->criteriaFactory->create($user, $filter, ClosureAnalyticsFilterRequestResolver::fromRequest($request));
        $event = $this->eventQuery->fetchEvent($criteria, $eventKey);
        if (!$event instanceof \App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventRow) {
            throw $this->createNotFoundException();
        }
        $children = $this->eventQuery->fetchChildren($criteria, $eventKey);

        return $this->render('@Statistics/closure_analytics/event.html.twig', [
            'event' => $event,
            'children' => $children,
        ]);
    }

    #[Route('/statistics/closure-analytics/details', name: 'app_stats_closure_analytics_details', methods: ['GET'])]
    public function details(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        if (StatisticsFilterScope::DispatchArea === $filter->scope) {
            throw $this->createNotFoundException();
        }

        return $this->render('@Statistics/closure_analytics/_details_frame.html.twig', [
            'dashboard' => $this->service->buildEvents(
                $this->criteriaFactory->create($user, $filter, ClosureAnalyticsFilterRequestResolver::fromRequest($request)),
                $request->query->getInt('page', 1),
            ),
        ]);
    }

    #[Route('/statistics/closure-analytics/intervals/{id<\d+>}', name: 'app_stats_closure_analytics_interval', methods: ['GET'])]
    public function interval(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        if (StatisticsFilterScope::DispatchArea === $filter->scope) {
            throw $this->createNotFoundException();
        }

        $interval = $this->intervalQuery->fetch(
            $this->criteriaFactory->create($user, $filter, ClosureAnalyticsFilterRequestResolver::fromRequest($request)),
            $id,
        );
        if (!$interval instanceof \App\Statistics\ClosureAnalytics\Application\DTO\ClosureIntervalRow) {
            throw $this->createNotFoundException();
        }

        return $this->render('@Statistics/closure_analytics/interval.html.twig', [
            'interval' => $interval,
        ]);
    }

    private function redirectUnsupportedScope(
        Request $request,
        StatisticsFilter $filter,
        string $route,
    ): ?Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            return $this->redirectToRoute($route, $publicRedirect['query']);
        }
        if (StatisticsFilterScope::DispatchArea !== $filter->scope) {
            return null;
        }

        $query = $request->query->all();
        $query['scope'] = StatisticsFilterScope::Public->value;
        $this->addFlash('warning', 'Leitstellenbereiche sind für Schließungsintervalle nicht definiert.');

        return $this->redirectToRoute($route, $query);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageVariables(
        Request $request,
        ?User $user,
        StatisticsFilter $filter,
        string $route,
        string $activeTab,
    ): array {
        $closureFilter = ClosureAnalyticsFilterRequestResolver::fromRequest($request);
        $baseCriteria = $this->criteriaFactory->create($user, $filter);
        $page = $this->pageViewModelFactory->create($request, $route, $user, $filter);
        $period = $this->periodViewModelFactory->create($request, $route, $filter);
        $filterViewModel = $this->filterViewModelFactory->create($baseCriteria, $closureFilter);
        $tabQuery = $request->query->all();
        unset($tabQuery['page'], $tabQuery['timeline_grain'], $tabQuery['timeline_from']);
        if (!\in_array($filter->scope, [StatisticsFilterScope::Hospital, StatisticsFilterScope::MyHospitals], true)) {
            unset($tabQuery[ClosureAnalyticsFilterRequestResolver::CLOSURE_UNITS]);
        }

        return [
            'closureAnalyticsActiveTab' => $activeTab,
            'closureAnalyticsTabs' => [
                [
                    'key' => 'overview',
                    'label' => 'stats.closure.tabs.overview',
                    'url' => $this->generateUrl('app_stats_closure_analytics', $tabQuery),
                ],
                [
                    'key' => 'timeline',
                    'label' => 'stats.closure.tabs.timeline',
                    'url' => $this->generateUrl('app_stats_closure_analytics_timeline', $tabQuery),
                ],
                [
                    'key' => 'events',
                    'label' => 'stats.closure.tabs.events',
                    'url' => $this->generateUrl('app_stats_closure_analytics_events', $tabQuery),
                ],
            ],
            'closureFilterDrawer' => $filterViewModel,
            'statsFilterDrawer' => $filterViewModel,
            'statsFilterDrawerResetUrl' => $this->generateUrl(
                $route,
                ClosureAnalyticsFilterRequestResolver::withoutFilters($request->query->all()),
            ),
            'statsShowFilterDrawer' => true,
            'statisticsFilter' => $page->filter,
            'statsScopeUrls' => $page->scopeUrls,
            'statsHospitalUrls' => $page->hospitalUrls,
            'cohortScopeChoices' => $page->cohortScopeChoices,
            'statsCohortDropdownSelectedName' => $page->cohortDropdownSelectedName,
            'statsScopePrimaryMenu' => $page->scopePrimaryMenu,
            'statsScopeSecondaryMenu' => $page->scopeSecondaryMenu,
            'statsShowScopeSecondaryPicker' => $page->showScopeSecondaryPicker,
            'statsScopePrimaryDropdownLabel' => $page->scopePrimaryDropdownLabel,
            'statsScopeSecondaryDropdownLabel' => $page->scopeSecondaryDropdownLabel,
            'statsPeriodUrls' => $page->periodUrls,
            'accessibleHospitals' => $page->accessibleHospitals,
            'statsHospitalDropdownSelectedName' => $page->hospitalDropdownSelectedName,
            'isLoggedIn' => $page->isLoggedIn,
            'statisticsHeadingScope' => $page->headingScope,
            'statisticsHeadingPeriod' => $period->headingLabel,
            'overviewPeriodViewModel' => $period,
            'statsUseOverviewPeriodControls' => true,
            'statsAnalysisContext' => $this->analysisContextFactory->create(
                $request,
                $route,
                $user,
                $filter,
                $page->headingScope,
                $period->headingLabel,
            ),
        ];
    }
}
