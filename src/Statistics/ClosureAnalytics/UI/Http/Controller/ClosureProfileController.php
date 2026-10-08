<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\ClosureAnalytics\Application\ClosureAnalyticsCriteriaFactory;
use App\Statistics\ClosureAnalytics\Application\ClosureAnalyticsHospitalScope;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileCompositionRow;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileCourseBandBuilder;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileCourseSeries;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileRef;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileView;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeStratum;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureProfileQuery;
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

#[IsGranted(UserRole::PARTICIPANT)]
final class ClosureProfileController extends AbstractController
{
    public function __construct(
        private readonly ClosureProfileQuery $profiles,
        private readonly ClosureAnalyticsCriteriaFactory $criteriaFactory,
        private readonly ClosureAnalyticsHospitalScope $hospitalScope,
        private readonly ClosureAnalyticsScopeRedirector $scopeRedirector,
        private readonly ClosureAnalyticsFilterViewModelFactory $filterViewModelFactory,
        private readonly StatisticsPageViewModelFactory $pageViewModelFactory,
        private readonly OverviewPeriodViewModelFactory $periodViewModelFactory,
        private readonly AnalysisContextViewModelFactory $analysisContextFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        '/statistics/closure-analytics/profiles/hospital/{hospitalId}',
        name: 'app_stats_closure_profile_hospital',
        requirements: ['hospitalId' => '\d+'],
        methods: ['GET'],
    )]
    public function hospital(
        int $hospitalId,
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        return $this->show(
            $request,
            $user,
            $filter,
            ClosureProfileRef::hospital($hospitalId),
            'app_stats_closure_profile_hospital',
            ['hospitalId' => $hospitalId],
        );
    }

    #[Route(
        '/statistics/closure-analytics/profiles/speciality/{hospitalId}/{specialityId}',
        name: 'app_stats_closure_profile_speciality',
        requirements: ['hospitalId' => '\d+', 'specialityId' => '\d+'],
        methods: ['GET'],
    )]
    public function speciality(
        int $hospitalId,
        int $specialityId,
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        return $this->show(
            $request,
            $user,
            $filter,
            ClosureProfileRef::speciality($hospitalId, $specialityId),
            'app_stats_closure_profile_speciality',
            ['hospitalId' => $hospitalId, 'specialityId' => $specialityId],
        );
    }

    #[Route(
        '/statistics/closure-analytics/profiles/speciality/{specialityId}',
        name: 'app_stats_closure_profile_speciality_scope',
        requirements: ['specialityId' => '\d+'],
        methods: ['GET'],
    )]
    public function specialityInScope(
        int $specialityId,
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        return $this->show(
            $request,
            $user,
            $filter,
            ClosureProfileRef::speciality(0, $specialityId),
            'app_stats_closure_profile_speciality_scope',
            ['specialityId' => $specialityId],
        );
    }

    #[Route(
        '/statistics/closure-analytics/profiles/department/{hospitalId}/{departmentId}',
        name: 'app_stats_closure_profile_department',
        requirements: ['hospitalId' => '\d+', 'departmentId' => '\d+'],
        methods: ['GET'],
    )]
    public function department(
        int $hospitalId,
        int $departmentId,
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        return $this->show(
            $request,
            $user,
            $filter,
            ClosureProfileRef::department($hospitalId, $departmentId),
            'app_stats_closure_profile_department',
            ['hospitalId' => $hospitalId, 'departmentId' => $departmentId],
        );
    }

    #[Route(
        '/statistics/closure-analytics/profiles/department/{departmentId}',
        name: 'app_stats_closure_profile_department_scope',
        requirements: ['departmentId' => '\d+'],
        methods: ['GET'],
    )]
    public function departmentInScope(
        int $departmentId,
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        return $this->show(
            $request,
            $user,
            $filter,
            ClosureProfileRef::department(0, $departmentId),
            'app_stats_closure_profile_department_scope',
            ['departmentId' => $departmentId],
        );
    }

    #[Route(
        '/statistics/closure-analytics/profiles/unit/{hospitalId}',
        name: 'app_stats_closure_profile_unit',
        requirements: ['hospitalId' => '\d+'],
        methods: ['GET'],
    )]
    public function unit(
        int $hospitalId,
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $unit = trim($request->query->getString('unit'));
        if ('' === $unit) {
            throw $this->createNotFoundException();
        }

        return $this->show(
            $request,
            $user,
            $filter,
            ClosureProfileRef::closureUnit($hospitalId, $unit),
            'app_stats_closure_profile_unit',
            ['hospitalId' => $hospitalId],
        );
    }

    #[Route(
        '/statistics/closure-analytics/profiles/group/{hospitalId}/{profileKey}',
        name: 'app_stats_closure_profile_group',
        requirements: ['hospitalId' => '\d+', 'profileKey' => '[a-f0-9]{32}'],
        methods: ['GET'],
    )]
    public function group(
        int $hospitalId,
        string $profileKey,
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        return $this->show(
            $request,
            $user,
            $filter,
            ClosureProfileRef::group($hospitalId, $profileKey),
            'app_stats_closure_profile_group',
            ['hospitalId' => $hospitalId, 'profileKey' => $profileKey],
        );
    }

    /**
     * @param array<string, int|string> $routeParams
     */
    private function show(
        Request $request,
        ?User $user,
        StatisticsFilter $filter,
        ClosureProfileRef $profile,
        string $route,
        array $routeParams,
    ): Response {
        $redirect = $this->redirectAssignedScope($request, $user, $route, $routeParams);
        if ($redirect instanceof Response) {
            return $redirect;
        }

        $closureFilter = ClosureAnalyticsFilterRequestResolver::fromRequest($request);
        $criteria = $this->criteriaFactory->create($user, $filter, $closureFilter);
        $allowed = $criteria->scope->hospitalIds ?? [];
        if ($profile->hospitalId > 0 && !\in_array($profile->hospitalId, $allowed, true)) {
            throw $this->createNotFoundException();
        }

        $profileCriteria = $criteria->withProfile($profile);
        $view = $this->profiles->view($profileCriteria, ClosureVolumeStratum::All);
        if (!$view instanceof ClosureProfileView) {
            throw $this->createNotFoundException();
        }

        $query = $request->query->all();
        $query[ClosureAnalyticsFilterRequestResolver::PROFILE] = $profile->toQuery();
        unset($query['page']);

        return $this->render('@Statistics/closure_analytics/profile.html.twig', [
            ...$this->pageVariables($request, $user, $filter, $route),
            'profile' => $view,
            'profileQuery' => $profile->toQuery(),
            'timelineUrl' => $this->generateUrl('app_stats_closure_analytics_timeline', $query),
            'eventsUrl' => $this->generateUrl('app_stats_closure_analytics_events', $query),
            'chartPayload' => [
                'heatmap' => [
                    'rowLabels' => array_map(
                        fn (int $day): string => $this->translator->trans('stats.closure.heatmap.weekday.'.$day, domain: 'statistics'),
                        range(1, 7),
                    ),
                    'columnLabels' => array_map(
                        static fn (int $slot): string => sprintf('%02d–%02d', $slot * 2, ($slot + 1) * 2),
                        range(0, 11),
                    ),
                    'matrix' => $view->heatmapMatrix,
                    'durationLabel' => $this->translator->trans('stats.closure.profile.events', domain: 'statistics'),
                ],
            ],
            'startCourses' => array_map(fn (ClosureProfileCourseSeries $series): array => $this->coursePayload($series, false, $profileCriteria), $view->startCourse),
            'endCourses' => array_map(fn (ClosureProfileCourseSeries $series): array => $this->coursePayload($series, true, $profileCriteria), $view->endCourse),
            'assignmentPhaseBreakdown' => $this->profiles->assignmentPhaseBreakdown($profileCriteria),
            'assignmentPhaseBreakdownProfile' => true,
            'profileTab' => 'composition' === $request->query->getString('tab') ? 'composition' : 'overview',
            'compositionRows' => $this->compositionRows($view),
        ]);
    }

    /**
     * @return list<ClosureProfileCompositionRow>
     */
    private function compositionRows(ClosureProfileView $view): array
    {
        $provenance = [];
        foreach ($view->departments as $department) {
            $provenance[$department->specialityName.'|'.$department->name] = match (true) {
                $department->fromClosure && $department->fromAssignment => 'both',
                $department->fromAssignment => 'assignment',
                default => 'closure',
            };
        }

        $rows = [];
        $seen = [];
        foreach ($view->members as $member) {
            $key = $member->specialityName.'|'.$member->departmentName;
            $seen[$key] = true;
            $rows[] = new ClosureProfileCompositionRow(
                $member->specialityName,
                $member->departmentName,
                $member->careLevel,
                $member->reason,
                $provenance[$key] ?? 'closure',
            );
        }
        foreach ($view->departments as $department) {
            $key = $department->specialityName.'|'.$department->name;
            if (isset($seen[$key])) {
                continue;
            }
            $rows[] = new ClosureProfileCompositionRow(
                $department->specialityName,
                $department->name,
                null,
                null,
                $provenance[$key] ?? 'assignment',
            );
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function coursePayload(ClosureProfileCourseSeries $series, bool $alignToEnd, ClosureAnalyticsCriteria $criteria): array
    {
        $categories = [];
        $observed = [];
        $expected = [];
        $mean = [];
        $points = [];
        foreach ($series->points as $point) {
            $categories[] = sprintf('%+d h', $point->offset);
            $observed[] = $point->lineSuppressed ? null : $point->observedMedian;
            $expected[] = $point->lineSuppressed ? null : $point->expectedMedian;
            $mean[] = $point->lineSuppressed ? null : $point->observedMean;
            $points[] = [
                'offset' => $point->offset,
                'events' => $point->eventCount,
                'reliableShare' => $point->reliableShare,
                'influenced' => $point->influenced,
                'boundary' => $point->memberBoundary,
                'closureShare' => $point->closureShare,
                'suppressed' => $point->lineSuppressed,
                'q1' => $point->lineSuppressed ? null : $point->observedQ1,
                'q3' => $point->lineSuppressed ? null : $point->observedQ3,
            ];
        }

        $seriesId = $series->combined ? 0 : $series->specialityId;

        return [
            'closureBands' => ClosureProfileCourseBandBuilder::bands($points, $alignToEnd),
            'anchorCategory' => ClosureProfileCourseBandBuilder::anchorCategory($points),
            'alignToEnd' => $alignToEnd,
            'chartId' => ($alignToEnd ? 'end' : 'start').'-'.$seriesId,
            'barSeries' => $this->profiles->courseBarSeries($criteria, $alignToEnd, $seriesId),
            'rateAxisLabel' => $this->translator->trans('stats.closure.profile.course.rate_axis', domain: 'statistics'),
            'barCountAxisLabel' => $this->translator->trans('stats.closure.volume.bar_series.axis', domain: 'statistics'),
            'assignmentsAxisLabel' => $this->translator->trans('stats.closure.volume.assignments_axis', domain: 'statistics'),
            'name' => $series->combined
                ? $this->translator->trans('stats.closure.profile.course.combined', domain: 'statistics')
                : $series->name,
            'combined' => $series->combined,
            'categories' => $categories,
            'observed' => $observed,
            'expected' => $expected,
            'mean' => $mean,
            'points' => $points,
            'observedLabel' => $this->translator->trans('stats.closure.profile.course.observed_rate', domain: 'statistics'),
            'expectedLabel' => $this->translator->trans('stats.closure.profile.course.expected_rate', domain: 'statistics'),
            'meanLabel' => $this->translator->trans('stats.closure.profile.duration_mean', domain: 'statistics'),
            'eventsLabel' => $this->translator->trans('stats.closure.profile.course.contributing', domain: 'statistics'),
            'spreadLabel' => $this->translator->trans('stats.closure.profile.duration_iqr', domain: 'statistics'),
            'qualityLabel' => $this->translator->trans('stats.closure.profile.course.quality', domain: 'statistics'),
            'suppressedLabel' => $this->translator->trans('stats.closure.profile.course.suppressed', domain: 'statistics'),
            'closureBandLabel' => $this->translator->trans('stats.closure.volume.closure_band', domain: 'statistics'),
            'closureShareLabel' => $this->translator->trans('stats.closure.profile.course.closure_share', domain: 'statistics'),
            'drawable' => [] !== array_filter($observed, static fn (mixed $value): bool => null !== $value),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pageVariables(Request $request, ?User $user, StatisticsFilter $filter, string $route): array
    {
        $closureFilter = ClosureAnalyticsFilterRequestResolver::fromRequest($request);
        $baseCriteria = $this->criteriaFactory->create($user, $filter);
        $page = $this->pageViewModelFactory->create($request, $route, $user, $filter);
        $period = $this->periodViewModelFactory->create($request, $route, $filter);
        $filterViewModel = $this->filterViewModelFactory->create($baseCriteria, $closureFilter);

        return [
            'closureFilterDrawer' => $filterViewModel,
            'statsFilterDrawer' => $filterViewModel,
            'statsFilterDrawerResetUrl' => $this->generateUrl(
                $route,
                [...$this->routeParams($request), ...ClosureAnalyticsFilterRequestResolver::withoutFilters($request->query->all())],
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

    /**
     * @return array<string, mixed>
     */
    private function routeParams(Request $request): array
    {
        $routeParams = $request->attributes->get('_route_params', []);

        return \is_array($routeParams) ? $routeParams : [];
    }

    /**
     * @param array<string, int|string> $routeParams
     */
    private function redirectAssignedScope(Request $request, ?User $user, string $route, array $routeParams): ?Response
    {
        $query = $this->scopeRedirector->canonicalRedirectQuery($request->query->all(), $user);
        if (null === $query) {
            return null;
        }

        return $this->redirectToRoute($route, [...$routeParams, ...$query]);
    }
}
