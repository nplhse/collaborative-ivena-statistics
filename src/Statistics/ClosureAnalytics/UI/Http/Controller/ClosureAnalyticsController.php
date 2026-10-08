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
use App\Statistics\ClosureAnalytics\Application\ClosureAnalyticsHospitalScope;
use App\Statistics\ClosureAnalytics\Application\ClosureAnalyticsService;
use App\Statistics\ClosureAnalytics\Application\ClosureDetailDayTimelineFactory;
use App\Statistics\ClosureAnalytics\Application\ClosureDurationLoadService;
use App\Statistics\ClosureAnalytics\Application\ClosureEventAssignmentPhase;
use App\Statistics\ClosureAnalytics\Application\ClosureEventAssignmentPopulation;
use App\Statistics\ClosureAnalytics\Application\ClosureEventLocalGroupResolver;
use App\Statistics\ClosureAnalytics\Application\ClosureOverlappingAllocationsFinder;
use App\Statistics\ClosureAnalytics\Application\ClosureUnitTableRows;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsFilter;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureIntervalRow;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureRecurringProfileTableQuery;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReadModel;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceConfig;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeSeriesScope;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeStratum;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureEventAllocationQuery;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureEventQuery;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureIntervalDetailQuery;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureProfileQuery;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureEventTableColumns;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureIntervalTableColumns;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureRecurringProfileTableColumns;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureUnitTableColumns;
use App\Statistics\UI\Http\Controller\AnalysisContextScopeMode;
use App\Statistics\UI\Http\Controller\AnalysisContextViewModelFactory;
use App\Statistics\UI\Http\Controller\OverviewPeriodViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Pagination\PaginatorInterface;

#[IsGranted(UserRole::PARTICIPANT)]
final class ClosureAnalyticsController extends AbstractController
{
    private const string ASSIGNMENTS_FRAME = 'stats-closure-event-assignments';

    public function __construct(
        private readonly ClosureAnalyticsService $service,
        private readonly ClosureIntervalDetailQuery $intervalQuery,
        private readonly ClosureEventQuery $eventQuery,
        private readonly ClosureAnalyticsChartPayloadFactory $chartPayloadFactory,
        private readonly ClosureDurationLoadService $durationLoadService,
        private readonly ClosureDurationLoadLabeler $durationLoadLabeler,
        private readonly ClosureAnalyticsFilterViewModelFactory $filterViewModelFactory,
        private readonly ClosureAnalyticsCriteriaFactory $criteriaFactory,
        private readonly StatisticsPageViewModelFactory $pageViewModelFactory,
        private readonly OverviewPeriodViewModelFactory $periodViewModelFactory,
        private readonly AnalysisContextViewModelFactory $analysisContextFactory,
        private readonly ClosureAnalyticsHospitalScope $hospitalScope,
        private readonly ClosureAnalyticsScopeRedirector $scopeRedirector,
        private readonly PaginatorInterface $paginator,
        private readonly DataTablePreferenceService $dataTablePreferences,
        private readonly ClosureEventTableColumns $eventTableColumns,
        private readonly ClosureIntervalTableColumns $intervalTableColumns,
        private readonly ClosureUnitTableColumns $unitTableColumns,
        private readonly ClosureRecurringProfileTableColumns $recurringProfileTableColumns,
        private readonly ClosureDetailDayTimelineFactory $detailDayTimelineFactory,
        private readonly ClosureAllocationExploreUrlFactory $allocationExploreUrlFactory,
        private readonly ClosureOverlappingAllocationsFinder $overlappingAllocationsFinder,
        private readonly ClosureVolumeReadModel $volume,
        private readonly ClosureEventAllocationQuery $eventAllocations,
        private readonly ClosureProfileQuery $profiles,
        private readonly ClosureEventLocalGroupResolver $localGroupResolver,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/statistics/closure-analytics', name: 'app_stats_closure_analytics', methods: ['GET'])]
    public function index(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $redirect = $this->redirectAssignedScope($request, $user, 'app_stats_closure_analytics');
        if ($redirect instanceof Response) {
            return $redirect;
        }
        $criteria = $this->criteriaFactory->create(
            $user,
            $filter,
            ClosureAnalyticsFilterRequestResolver::fromRequest($request),
        );
        $dashboard = $this->service->buildOverview($criteria);
        $unitSchema = $this->unitTableColumns->preferenceSchema();
        $unitQueryPreferences = DataTablePreferenceQueryState::fromRequest(
            $request,
            ClosureUnitTableState::COLUMNS_PARAM,
            ClosureUnitTableState::COLUMN_ORDER_PARAM,
            ClosureUnitTableState::LIMIT_PARAM,
        );
        $unitPreferences = $this->dataTablePreferences->resolve(
            $user,
            $unitSchema,
            $unitQueryPreferences->visibleColumns,
            $unitQueryPreferences->columnOrder,
            $unitQueryPreferences->pageSize,
        );
        $unitTable = ClosureUnitTableState::fromRequest(
            $request,
            $unitPreferences->pageSize,
            $unitPreferences->sortBy,
            $unitPreferences->orderBy,
        );
        $unitRows = ClosureUnitTableRows::sorted(
            $dashboard->breakdowns['closure_unit'],
            $dashboard->metrics->closedMinutes,
            $unitTable->sortBy,
            $unitTable->orderBy,
        );

        return $this->render('@Statistics/closure_analytics/index.html.twig', [
            ...$this->pageVariables($request, $user, $filter, 'app_stats_closure_analytics', 'overview'),
            'dashboard' => $dashboard,
            'chartPayload' => $this->chartPayloadFactory->dashboard($dashboard->timeSeries, $dashboard->heatmap),
            'closureUnits' => $this->paginator
                ->fromCallbacks(
                    static fn (int $offset, int $limit): array => \array_slice($unitRows, $offset, $limit),
                    static fn (): int => \count($unitRows),
                )
                ->perPage(max(1, $unitTable->limit))
                ->pageParameter(ClosureUnitTableState::PAGE_PARAM)
                ->paginate($unitTable->page),
            'closureUnitTable' => $unitTable,
            'closureUnitColumns' => ClosureUnitTableColumns::columns(),
            'closureUnitPreferences' => $unitPreferences,
            'closureUnitPreferenceKey' => $unitSchema->key,
            'closureUnitPreferencesPersisted' => $user instanceof User,
            'volumeBurden' => $dashboard->hasIntervals()
                ? $this->volume->burden($criteria, ClosureVolumeStratum::All)
                : null,
        ]);
    }

    #[Route('/statistics/closure-analytics/profiles', name: 'app_stats_closure_analytics_profiles', methods: ['GET'])]
    public function profiles(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $redirect = $this->redirectAssignedScope($request, $user, 'app_stats_closure_analytics_profiles');
        if ($redirect instanceof Response) {
            return $redirect;
        }
        $criteria = $this->criteriaFactory->create(
            $user,
            $filter,
            ClosureAnalyticsFilterRequestResolver::fromRequest($request),
        );
        $showHospital = \count($criteria->scope->hospitalIds ?? []) > 1;
        $schema = $this->recurringProfileTableColumns->preferenceSchema();
        $queryPreferences = DataTablePreferenceQueryState::fromRequest(
            $request,
            'profilesColumns',
            'profilesColumnOrder',
            ClosureRecurringProfileTableState::LIMIT_PARAM,
        );
        $preferences = $this->dataTablePreferences->resolve(
            $user,
            $schema,
            $queryPreferences->visibleColumns,
            $queryPreferences->columnOrder,
            $queryPreferences->pageSize,
        );
        $tableState = ClosureRecurringProfileTableState::fromRequest(
            $request,
            $preferences->pageSize,
            $preferences->sortBy,
            $preferences->orderBy,
        );
        $recurringProfiles = $this->paginator
            ->fromCallbacks(
                function (int $offset, int $limit) use ($criteria, $tableState): array {
                    $page = max(1, (int) floor($offset / max(1, $limit)) + 1);

                    return $this->profiles->recurringGroupPage(
                        $criteria,
                        new ClosureRecurringProfileTableQuery(
                            $page,
                            $limit,
                            $tableState->sortBy,
                            $tableState->orderBy,
                            $tableState->profileQ,
                        ),
                    )->rows;
                },
                fn (): int => $this->profiles->recurringGroupPage($criteria, $tableState->toTableQuery())->total,
            )
            ->perPage(max(1, $tableState->limit))
            ->pageParameter(ClosureRecurringProfileTableState::PAGE_PARAM)
            ->paginate($tableState->page);

        return $this->render('@Statistics/closure_analytics/profiles.html.twig', [
            ...$this->pageVariables($request, $user, $filter, 'app_stats_closure_analytics_profiles', 'profiles'),
            'recurringProfiles' => $recurringProfiles,
            'recurringProfileTable' => $tableState,
            'recurringProfileColumns' => ClosureRecurringProfileTableColumns::columns($showHospital),
            'recurringProfilePreferences' => $preferences,
            'recurringProfilePreferenceKey' => $schema->key,
            'recurringProfilePreferencesPersisted' => $user instanceof User,
        ]);
    }

    #[Route('/statistics/closure-analytics/duration', name: 'app_stats_closure_analytics_duration', methods: ['GET'])]
    public function duration(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $redirect = $this->redirectAssignedScope($request, $user, 'app_stats_closure_analytics_duration');
        if ($redirect instanceof Response) {
            return $redirect;
        }
        $criteria = $this->criteriaFactory->create(
            $user,
            $filter,
            ClosureAnalyticsFilterRequestResolver::fromRequest($request),
        );
        $durationLoad = $this->durationLoadLabeler->label($this->durationLoadService->build($criteria));

        return $this->render('@Statistics/closure_analytics/duration.html.twig', [
            ...$this->pageVariables($request, $user, $filter, 'app_stats_closure_analytics_duration', 'duration'),
            'durationLoad' => $durationLoad,
            'chartPayload' => [
                'durationLoad' => $this->chartPayloadFactory->durationLoad($durationLoad),
            ],
        ]);
    }

    #[Route('/statistics/closure-analytics/timeline', name: 'app_stats_closure_analytics_timeline', methods: ['GET'])]
    public function timeline(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $redirect = $this->redirectAssignedScope($request, $user, 'app_stats_closure_analytics_timeline');
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
        $redirect = $this->redirectAssignedScope($request, $user, 'app_stats_closure_analytics_events');
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

    #[Route('/statistics/closure-analytics/events/{eventKey}', name: 'app_stats_closure_analytics_event', requirements: ['eventKey' => '\d+'], methods: ['GET'])]
    public function event(
        string $eventKey,
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $redirect = $this->redirectAssignedScope($request, $user, 'app_stats_closure_analytics_event', [
            'eventKey' => $eventKey,
        ]);
        if ($redirect instanceof Response) {
            return $redirect;
        }

        $closureFilter = ClosureAnalyticsFilterRequestResolver::fromRequest($request);
        $criteria = $this->criteriaFactory->create($user, $filter, $closureFilter);
        $event = $this->eventQuery->fetchEvent($criteria, $eventKey);
        if (!$event instanceof ClosureEventRow
            || !\in_array($event->hospitalId, $this->hospitalScope->allowedHospitalIds($user), true)
        ) {
            throw $this->createNotFoundException();
        }
        $children = $this->eventQuery->fetchChildren($criteria, $eventKey);
        [$timelineDays, $timelineContextTypes] = $this->detailDayTimelineFactory->build(
            $criteria,
            $children,
            $eventKey,
            $request->query->all(),
        );

        if (self::ASSIGNMENTS_FRAME === $request->headers->get('Turbo-Frame')) {
            return $this->renderEventAssignmentsFrame($event, (int) $eventKey, $request);
        }

        $volumeSeriesScope = $this->volumeSeriesScopeFromRequest($request);
        $assignmentVariables = $this->eventAssignmentVariables(
            $event,
            (int) $eventKey,
            $request,
            $volumeSeriesScope->assignmentPopulation(),
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
            'volumeDetail' => $this->volume->detail(
                $criteria,
                (int) $eventKey,
                $event->hospitalId,
                ClosureVolumeStratum::All,
                $volumeSeriesScope,
            ),
            'volumeScopeToggle' => true,
            'volumeSeriesScope' => $volumeSeriesScope,
            'eventTab' => 'course' === $request->query->getString('tab') ? 'course' : 'overview',
            ...$assignmentVariables,
            'profileLinks' => $this->profiles->linksForEvent((int) $eventKey, $event->hospitalId, $criteria),
            'localGroups' => $this->localGroupResolver->resolve($event->hospitalId, $eventKey, $children),
        ]);
    }

    #[Route('/statistics/closure-analytics/details', name: 'app_stats_closure_analytics_details', methods: ['GET'])]
    public function details(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $redirect = $this->redirectAssignedScope($request, $user, 'app_stats_closure_analytics_details');
        if ($redirect instanceof Response) {
            return $redirect;
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
        $redirect = $this->redirectAssignedScope($request, $user, 'app_stats_closure_analytics_interval', [
            'id' => $id,
        ]);
        if ($redirect instanceof Response) {
            return $redirect;
        }

        $closureFilter = ClosureAnalyticsFilterRequestResolver::fromRequest($request);
        $criteria = $this->criteriaFactory->create($user, $filter, $closureFilter);
        $interval = $this->intervalQuery->fetch($criteria, $id);
        if (!$interval instanceof ClosureIntervalRow
            || !\in_array($interval->hospitalId, $this->hospitalScope->allowedHospitalIds($user), true)
        ) {
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
            'overlappingAllocations' => $this->overlappingAllocationsFinder->forIntervals([$interval]),
            'actions' => $this->allocationActions(
                $filter,
                $closureFilter,
                $interval->hospitalId,
                $interval->startsAt,
                $interval->endsAt,
            ),
            'volumeDetail' => $this->volume->detail(
                $criteria,
                (int) $interval->eventKey,
                $interval->hospitalId,
                ClosureVolumeStratum::All,
                ClosureVolumeSeriesScope::Department,
            ),
            'volumeScopeToggle' => false,
            'volumeSeriesScope' => ClosureVolumeSeriesScope::Department,
        ]);
    }

    private function volumeSeriesScopeFromRequest(Request $request): ClosureVolumeSeriesScope
    {
        return 'speciality' === $request->query->getString('volumeSeries')
            ? ClosureVolumeSeriesScope::Speciality
            : ClosureVolumeSeriesScope::Department;
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

    private function assignmentListFilter(Request $request): ClosureVolumeStratum
    {
        $value = $request->query->get('assignmentList');

        return ClosureVolumeStratum::fromRequest(\is_string($value) ? $value : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventAssignmentVariables(
        ClosureEventRow $event,
        int $eventId,
        Request $request,
        ClosureEventAssignmentPopulation $population,
    ): array {
        $listFilter = $this->assignmentListFilter($request);
        $phase = ClosureEventAssignmentPhase::fromRequest($request->query->getString('phase'));
        $counts = $this->eventAllocations->phaseCounts(
            $eventId,
            $event->hospitalId,
            $event->startsAt,
            $event->endsAt,
            $population,
            $listFilter,
        );
        [$phaseFrom, $phaseTo] = $phase->bounds($event->startsAt, $event->endsAt);
        $requestedPageSize = $request->query->getInt('assignmentLimit');
        $pageSize = \in_array($requestedPageSize, [25, 50, 100], true)
            ? $requestedPageSize
            : ClosureEventAllocationQuery::PAGE_SIZE;
        $assignments = $this->paginator
            ->fromCallbacks(
                fn (int $offset, int $limit): array => $this->eventAllocations->assignments(
                    $eventId,
                    $event->hospitalId,
                    $phaseFrom,
                    $phaseTo,
                    $population,
                    $listFilter,
                    $offset,
                    $limit,
                ),
                fn (): int => $counts[$phase->value],
            )
            ->pageParameter('assignmentPage')
            ->perPage($pageSize)
            ->paginate();
        $contextHours = ClosureVolumeReferenceConfig::CONTEXT_HOURS;

        return [
            'assignmentPhase' => $phase,
            'assignmentPopulation' => $population,
            'assignmentListFilter' => $listFilter,
            'assignmentCounts' => $counts,
            'assignmentPhaseBreakdown' => $this->eventAllocations->phaseBreakdown(
                $eventId,
                $event->hospitalId,
                $event->startsAt,
                $event->endsAt,
                $population,
            ),
            'assignmentBeforeFrom' => $event->startsAt->modify(sprintf('-%d hours', $contextHours)),
            'assignmentAfterTo' => $event->endsAt->modify(sprintf('+%d hours', $contextHours)),
            'assignmentDuringFrom' => $event->startsAt,
            'assignmentDuringTo' => $event->endsAt,
            'assignments' => $assignments,
        ];
    }

    private function renderEventAssignmentsFrame(ClosureEventRow $event, int $eventId, Request $request): Response
    {
        return $this->render('@Statistics/closure_analytics/_event_assignments_frame.html.twig', [
            ...$this->eventAssignmentVariables(
                $event,
                $eventId,
                $request,
                $this->volumeSeriesScopeFromRequest($request)->assignmentPopulation(),
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $routeParams
     */
    private function redirectAssignedScope(
        Request $request,
        ?User $user,
        string $route,
        array $routeParams = [],
    ): ?Response {
        $query = $this->scopeRedirector->canonicalRedirectQuery($request->query->all(), $user);
        if (null === $query) {
            return null;
        }

        return $this->redirectToRoute($route, [...$routeParams, ...$query]);
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
                    'key' => 'duration',
                    'label' => 'stats.closure.tabs.duration',
                    'url' => $this->generateUrl('app_stats_closure_analytics_duration', $tabQuery),
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
                [
                    'key' => 'profiles',
                    'label' => 'stats.closure.tabs.profiles',
                    'url' => $this->generateUrl('app_stats_closure_analytics_profiles', $tabQuery),
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
                scopeMode: AnalysisContextScopeMode::AssignedHospitals,
            ),
            'closureMissingHospitalAccess' => [] === $this->hospitalScope->allowedHospitalIds($user),
        ];
    }
}
