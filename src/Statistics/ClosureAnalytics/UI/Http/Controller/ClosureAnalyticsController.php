<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Http\Controller;

use App\Allocation\Application\DTO\CatalogAction;
use App\Shared\Application\DataTable\DataTablePreferenceService;
use App\Shared\UI\Http\DataTablePreferenceQueryState;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\ClosureAnalytics\Application\ClosureAllocationExploreUrlFactory;
use App\Statistics\ClosureAnalytics\Application\ClosureAnalyticsCriteriaFactory;
use App\Statistics\ClosureAnalytics\Application\ClosureAnalyticsService;
use App\Statistics\ClosureAnalytics\Application\ClosureDetailDayTimelineFactory;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsFilter;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureIntervalRow;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureEventQuery;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureIntervalDetailQuery;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureEventTableColumns;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureIntervalTableColumns;
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
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Pagination\PaginatorInterface;

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
        private readonly PaginatorInterface $paginator,
        private readonly DataTablePreferenceService $dataTablePreferences,
        private readonly ClosureEventTableColumns $eventTableColumns,
        private readonly ClosureIntervalTableColumns $intervalTableColumns,
        private readonly ClosureDetailDayTimelineFactory $detailDayTimelineFactory,
        private readonly ClosureAllocationExploreUrlFactory $allocationExploreUrlFactory,
        private readonly TranslatorInterface $translator,
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

        $closureFilter = ClosureAnalyticsFilterRequestResolver::fromRequest($request);
        $criteria = $this->criteriaFactory->create($user, $filter, $closureFilter);
        $event = $this->eventQuery->fetchEvent($criteria, $eventKey);
        if (!$event instanceof ClosureEventRow) {
            throw $this->createNotFoundException();
        }
        $children = $this->eventQuery->fetchChildren($criteria, $eventKey);
        [$timelineDays, $timelineContextTypes] = $this->detailDayTimelineFactory->build(
            $criteria,
            $children,
            $eventKey,
            $request->query->all(),
        );

        return $this->render('@Statistics/closure_analytics/event.html.twig', [
            'event' => $event,
            'children' => $children,
            'timelineDays' => $timelineDays,
            'timelineContextTypes' => $timelineContextTypes,
            'actions' => $this->allocationActions(
                $filter,
                $closureFilter,
                $event->hospitalId,
                $event->startsAt,
                $event->endsAt,
            ),
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

        $criteria = $this->criteriaFactory->create(
            $user,
            $filter,
            ClosureAnalyticsFilterRequestResolver::fromRequest($request),
        );
        $requestedTable = ClosureEventTableState::fromRequest($request);
        $intervalView = 'intervals' === $requestedTable->view;
        $columnDefinition = $intervalView ? $this->intervalTableColumns : $this->eventTableColumns;
        $schema = $columnDefinition->preferenceSchema();
        $queryPreferences = DataTablePreferenceQueryState::fromRequest($request);
        $tablePreferences = $this->dataTablePreferences->resolve(
            $user,
            $schema,
            $queryPreferences->visibleColumns,
            $queryPreferences->columnOrder,
            $queryPreferences->pageSize,
        );
        $table = ClosureEventTableState::fromRequest($request, $tablePreferences->pageSize);
        $events = $this->paginator
            ->fromCallbacks(
                fn (int $offset, int $limit): array => $intervalView
                    ? $this->eventQuery->fetchIntervals($criteria, $offset, $limit, $table->sortBy, $table->orderBy)
                    : $this->eventQuery->fetchEvents($criteria, $offset, $limit, $table->sortBy, $table->orderBy),
                fn (): int => $intervalView
                    ? $this->eventQuery->countIntervals($criteria)
                    : $this->eventQuery->countEvents($criteria),
            )
            ->perPage(max(1, $table->limit))
            ->paginate(page: $table->page);

        return $this->render('@Statistics/closure_analytics/_details_frame.html.twig', [
            'events' => $events,
            'table' => $table,
            'columns' => $intervalView ? ClosureIntervalTableColumns::columns() : ClosureEventTableColumns::columns(),
            'tablePreferences' => $tablePreferences,
            'tablePreferenceKey' => $schema->key,
            'tablePreferencesPersisted' => $user instanceof User,
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

        $closureFilter = ClosureAnalyticsFilterRequestResolver::fromRequest($request);
        $criteria = $this->criteriaFactory->create($user, $filter, $closureFilter);
        $interval = $this->intervalQuery->fetch($criteria, $id);
        if (!$interval instanceof ClosureIntervalRow) {
            throw $this->createNotFoundException();
        }

        [$timelineDays, $timelineContextTypes] = $this->detailDayTimelineFactory->build(
            $criteria,
            [$interval],
            $interval->eventKey,
            $request->query->all(),
        );

        return $this->render('@Statistics/closure_analytics/interval.html.twig', [
            'interval' => $interval,
            'timelineDays' => $timelineDays,
            'timelineContextTypes' => $timelineContextTypes,
            'actions' => $this->allocationActions(
                $filter,
                $closureFilter,
                $interval->hospitalId,
                $interval->startsAt,
                $interval->endsAt,
            ),
        ]);
    }

    /**
     * @return list<CatalogAction>
     */
    private function allocationActions(
        StatisticsFilter $filter,
        ClosureAnalyticsFilter $closureFilter,
        int $hospitalId,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ): array {
        return [
            new CatalogAction(
                label: $this->translator->trans('stats.closure.event.view_allocations', [], 'statistics'),
                url: $this->allocationExploreUrlFactory->listUrl(
                    $filter,
                    $closureFilter,
                    $hospitalId,
                    $startsAt,
                    $endsAt,
                ),
                icon: 'tabler:list',
                primary: true,
            ),
        ];
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
        if (!$filterViewModel['showHospitals']) {
            unset($tabQuery[ClosureAnalyticsFilterRequestResolver::HOSPITALS]);
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
