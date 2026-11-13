<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Overview\AggregateHospitalScope;
use App\Encounter\Application\Overview\EncounterOverviewFactory;
use App\Encounter\Application\Overview\EncounterPeriod;
use App\Encounter\Application\Overview\TimeGrain;
use App\Encounter\Application\Review\ReviewPeriod;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\TimeSeries\TimeSeriesAxisFiller;
use App\Statistics\Application\TimeSeries\TimeSeriesGrainResolver;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Descriptive overview of the encounter data pilot (#1055, Behandlungsdaten) as a statistics page.
 *
 * Always exactly one hospital: the scope and period picker runs in AnalysisContextScopeMode::AssignedHospital
 * with the hospitals of AggregateHospitalScope (VIEW_AGGREGATES; administrators every hospital with
 * visits). Any other scope in the URL is rewritten onto one of them, so hospitals are never added up.
 * The period is the statistics period (StatisticsPeriodResolver), applied to the visits' time anchor.
 * Figures come from the Encounter context (EncounterOverviewFactory, documented partnership).
 * Persons who may review the hospital's links (EncounterAttribute::REVIEW) get links to the match review
 * and the match validation, with the statistics period when it fits into one review page.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class EncounterAnalyticsController extends AbstractController
{
    public const string ROUTE = 'app_stats_encounter_analytics';

    public const array TABS = ['overview', 'process', 'link', 'distributions'];

    public function __construct(
        private readonly AggregateHospitalScope $hospitalScope,
        private readonly EncounterOverviewFactory $overviewFactory,
        private readonly AnalysisContextViewModelFactory $analysisContextFactory,
        private readonly OverviewPeriodViewModelFactory $periodViewModelFactory,
        private readonly ClockInterface $clock,
        private readonly HospitalLookupInterface $hospitalLookup,
    ) {
    }

    #[Route('/statistics/encounter-analytics', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $hospitals = $this->hospitalScope->forCurrentUser();
        if ([] === $hospitals) {
            return $this->render('@Statistics/encounter_analytics/no_access.html.twig');
        }

        $requested = $this->requestedHospitalId($request);
        if (!\array_key_exists($requested, $hospitals) || !$this->isCanonical($request)) {
            $target = \array_key_exists($requested, $hospitals) ? $requested : array_key_first($hospitals);

            return $this->redirectToRoute(self::ROUTE, $this->canonicalQuery($request, $target));
        }

        $bounds = StatisticsPeriodResolver::resolve($filter);
        // Same time axis as every statistics time series: days for a month, months otherwise; no incomplete month.
        $statisticsGrain = TimeSeriesGrainResolver::resolve($filter->period);
        $now = $this->clock->now();
        $overview = $this->overviewFactory->create(
            $requested,
            EncounterPeriod::fromBounds($bounds->from, $bounds->toExclusive),
            TimeGrain::from($statisticsGrain->value),
            static fn (array $visitsByKey): array => array_column(TimeSeriesAxisFiller::fill($statisticsGrain, $bounds, $visitsByKey, $now), 'key'),
        );
        $period = $this->periodViewModelFactory->create($request, self::ROUTE, $filter);
        $tab = \in_array($request->query->getString('tab'), self::TABS, true) ? $request->query->getString('tab') : self::TABS[0];
        $tabQuery = $request->query->all();
        unset($tabQuery['tab']);

        $hospital = $this->hospitalLookup->findById($requested);
        $canReview = $hospital instanceof Hospital && $this->isGranted(EncounterAttribute::REVIEW, $hospital);

        return $this->render('@Statistics/encounter_analytics/index.html.twig', [
            'hospitalId' => $requested,
            'canReview' => $canReview,
            'reviewQuery' => $canReview ? $this->reviewQuery($bounds->from, $bounds->toExclusive) : [],
            'hospitalName' => $hospitals[$requested],
            'overview' => $overview,
            'tab' => $tab,
            'tabs' => self::TABS,
            'tabQuery' => $tabQuery,
            'statsAnalysisContext' => $this->analysisContextFactory->create(
                $request,
                self::ROUTE,
                $user,
                $filter,
                $hospitals[$requested],
                $period->headingLabel,
                scopeMode: AnalysisContextScopeMode::AssignedHospital,
                assignedHospitals: $hospitals,
            ),
        ]);
    }

    /**
     * The review reads whole days and at most ReviewPeriod::MAX_DAYS; a longer statistics period is not cut
     * down (nor is an open-ended one), the review then opens with its own default period.
     *
     * @return array<string, string>
     */
    private function reviewQuery(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive): array
    {
        if (!$from instanceof \DateTimeImmutable || !$toExclusive instanceof \DateTimeImmutable) {
            return [];
        }

        $to = $toExclusive->modify('-1 day');
        if ($from->diff($to)->days >= ReviewPeriod::MAX_DAYS) {
            return [];
        }

        return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    }

    private function requestedHospitalId(Request $request): int
    {
        $scope = $request->query->getString(StatisticsQueryKeys::SCOPE);
        $hospital = $request->query->getString(StatisticsQueryKeys::HOSPITAL);
        if (str_starts_with($scope, StatisticsFilterScope::Hospital->value.':')) {
            $hospital = substr($scope, \strlen(StatisticsFilterScope::Hospital->value) + 1);
        }

        return ctype_digit($hospital) ? (int) $hospital : 0;
    }

    private function isCanonical(Request $request): bool
    {
        foreach ([StatisticsQueryKeys::STATE, StatisticsQueryKeys::COHORT, StatisticsQueryKeys::DISPATCH_AREA] as $key) {
            if ('' !== $request->query->getString($key)) {
                return false;
            }
        }

        return StatisticsFilterScope::Hospital->value === $request->query->getString(StatisticsQueryKeys::SCOPE);
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalQuery(Request $request, int $hospitalId): array
    {
        $query = $request->query->all();
        unset($query[StatisticsQueryKeys::STATE], $query[StatisticsQueryKeys::COHORT], $query[StatisticsQueryKeys::DISPATCH_AREA]);
        $query[StatisticsQueryKeys::SCOPE] = StatisticsFilterScope::Hospital->value;
        $query[StatisticsQueryKeys::HOSPITAL] = (string) $hospitalId;

        return $query;
    }
}
