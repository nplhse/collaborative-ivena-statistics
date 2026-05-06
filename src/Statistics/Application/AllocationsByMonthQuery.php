<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\DTO\AllocationsOverTimeSeries;
use App\Statistics\Application\DTO\AllocationsOverTimeSeriesSegment;
use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Infrastructure\Repository\AllocationStatsProjectionRepository;

/**
 * Fetches allocations per month for the selected statistics period and scope (public / my hospitals / single hospital).
 */
final readonly class AllocationsByMonthQuery
{
    /** @var array<int, string> */
    private const array URGENCY_SHORT_LABEL_KEYS = [
        1 => 'stats.overview.hospital_summary.urgency_u1',
        2 => 'stats.overview.hospital_summary.urgency_u2',
        3 => 'stats.overview.hospital_summary.urgency_u3',
    ];

    public function __construct(
        private AllocationStatsProjectionRepository $projectionRepository,
        private StatisticsScopeResolver $scopeResolver,
    ) {
    }

    public function fetch(StatisticsContext $context, StatisticsAnalysisDimension $dimension): AllocationsOverTimeSeries
    {
        if (StatisticsAnalysisDimension::Features === $dimension) {
            return $this->fetchClinicalFeatureMonthlySeries($context);
        }
        if (StatisticsAnalysisDimension::Resources === $dimension) {
            return $this->fetchResourcesRequiredMonthlySeries($context);
        }

        $period = $context->filter->period;

        return match ($period) {
            StatisticsFilterPeriod::All => $this->fetchRolling12Months($context, $dimension),
            StatisticsFilterPeriod::AllTime => $this->fetchAllTime($context, $dimension),
            StatisticsFilterPeriod::Year => $this->fetchBoundedYearMonths($context, $dimension),
            StatisticsFilterPeriod::Month => $this->fetchBoundedDailyInMonth($context, $dimension),
        };
    }

    /**
     * null = full public aggregation (no hospital IN filter); non-empty list = IN clause.
     *
     * @return list<int>|null
     */
    private function hospitalIdsOrNull(StatisticsContext $context): ?array
    {
        return $this->scopeResolver->hospitalIdsOrNull($context);
    }

    private function fetchRolling12Months(StatisticsContext $context, StatisticsAnalysisDimension $dimension): AllocationsOverTimeSeries
    {
        $currentMonth = new \DateTimeImmutable('first day of this month 00:00:00');
        $start = StatisticsPeriod::overviewPeriodStart();

        $monthKeys = [];
        $labels = [];
        $cursor = $start;
        while ($cursor <= $currentMonth) {
            $monthKeys[] = $cursor->format('Y-m');
            $labels[] = $cursor->format('M');
            $cursor = $cursor->modify('+1 month');
        }

        $hospitalIds = $this->hospitalIdsOrNull($context);

        return match ($dimension) {
            StatisticsAnalysisDimension::Total => $this->fetchTotalRolling($labels, $monthKeys, $hospitalIds),
            StatisticsAnalysisDimension::Gender => $this->fetchGenderRolling($labels, $monthKeys, $hospitalIds),
            StatisticsAnalysisDimension::Urgency => $this->fetchUrgencyRolling($labels, $monthKeys, $hospitalIds),
            StatisticsAnalysisDimension::Features,
            StatisticsAnalysisDimension::Resources => throw new \LogicException('Features and Resources must not reach rolling-month fetch (handled at start of fetch()).'),
        };
    }

    /** @var list<array{key: string, labelKey: string}> */
    private const array CLINICAL_FEATURE_SEGMENT_DEFINITIONS = [
        ['key' => 'with_physician', 'labelKey' => 'statistics.distribution.dim.is_with_physician'],
        ['key' => 'cpr', 'labelKey' => 'statistics.distribution.dim.is_cpr'],
        ['key' => 'ventilated', 'labelKey' => 'statistics.distribution.dim.is_ventilated'],
        ['key' => 'shock', 'labelKey' => 'stats.analysis.feature.is_shock'],
        ['key' => 'pregnant', 'labelKey' => 'stats.analysis.feature.is_pregnant'],
        ['key' => 'infectious', 'labelKey' => 'field.infection'],
    ];

    public function fetchResourcesRequiredMonthlySeries(StatisticsContext $context): AllocationsOverTimeSeries
    {
        $period = $context->filter->period;

        return match ($period) {
            StatisticsFilterPeriod::All => $this->fetchResourcesRequiredRolling12Months($context),
            StatisticsFilterPeriod::AllTime => $this->fetchResourcesRequiredAllTimeSeasonality($context),
            StatisticsFilterPeriod::Year => $this->fetchResourcesRequiredBoundedPeriod($context),
            StatisticsFilterPeriod::Month => $this->fetchResourcesRequiredDailyInMonth($context),
        };
    }

    public function fetchClinicalFeatureMonthlySeries(StatisticsContext $context): AllocationsOverTimeSeries
    {
        $period = $context->filter->period;

        return match ($period) {
            StatisticsFilterPeriod::All => $this->fetchClinicalFeaturesRolling12Months($context),
            StatisticsFilterPeriod::AllTime => $this->fetchClinicalFeaturesAllTimeSeasonality($context),
            StatisticsFilterPeriod::Year => $this->fetchClinicalFeaturesBoundedPeriod($context),
            StatisticsFilterPeriod::Month => $this->fetchClinicalFeaturesDailyInMonth($context),
        };
    }

    private function fetchResourcesRequiredRolling12Months(StatisticsContext $context): AllocationsOverTimeSeries
    {
        [$labels, $monthKeys] = $this->buildRolling12MonthAxis();
        $hospitalIds = $this->hospitalIdsOrNull($context);
        $buckets = $this->projectionRepository->bucketResourcesByMonth(StatisticsPeriod::overviewPeriodStart(), null, $hospitalIds);

        return $this->mapResourcesRequiredBucketsToSeries($labels, $monthKeys, $buckets);
    }

    private function fetchResourcesRequiredBoundedPeriod(StatisticsContext $context): AllocationsOverTimeSeries
    {
        [$labels, $monthKeys, $start, $toExclusive] = $this->buildBoundedMonthAxis($context);
        $hospitalIds = $this->hospitalIdsOrNull($context);
        $buckets = $this->projectionRepository->bucketResourcesByMonth($start, $toExclusive, $hospitalIds);

        return $this->mapResourcesRequiredBucketsToSeries($labels, $monthKeys, $buckets);
    }

    private function fetchResourcesRequiredAllTimeSeasonality(StatisticsContext $context): AllocationsOverTimeSeries
    {
        [$labels, $monthKeys] = $this->buildCalendarMonthOfYearAxis();
        $start = $this->effectiveAllTimeQueryStart($context);
        $hospitalIds = $this->hospitalIdsOrNull($context);
        $buckets = $this->projectionRepository->bucketResourcesByCalendarMonth($start, null, $hospitalIds);

        return $this->mapResourcesRequiredBucketsToSeries($labels, $monthKeys, $buckets);
    }

    private function fetchResourcesRequiredDailyInMonth(StatisticsContext $context): AllocationsOverTimeSeries
    {
        [$labels, $monthKeys, $start, $toExclusive] = $this->buildDailyAxis($context);
        $hospitalIds = $this->hospitalIdsOrNull($context);
        $buckets = $this->projectionRepository->bucketResourcesByDay($start, $toExclusive, $hospitalIds);

        return $this->mapResourcesRequiredBucketsToSeries($labels, $monthKeys, $buckets);
    }

    private function fetchClinicalFeaturesRolling12Months(StatisticsContext $context): AllocationsOverTimeSeries
    {
        [$labels, $monthKeys] = $this->buildRolling12MonthAxis();
        $hospitalIds = $this->hospitalIdsOrNull($context);
        $buckets = $this->projectionRepository->bucketClinicalFeaturesByMonth(StatisticsPeriod::overviewPeriodStart(), null, $hospitalIds);

        return $this->mapClinicalFeaturesBucketsToSeries($labels, $monthKeys, $buckets);
    }

    private function fetchClinicalFeaturesBoundedPeriod(StatisticsContext $context): AllocationsOverTimeSeries
    {
        [$labels, $monthKeys, $start, $toExclusive] = $this->buildBoundedMonthAxis($context);
        $hospitalIds = $this->hospitalIdsOrNull($context);
        $buckets = $this->projectionRepository->bucketClinicalFeaturesByMonth($start, $toExclusive, $hospitalIds);

        return $this->mapClinicalFeaturesBucketsToSeries($labels, $monthKeys, $buckets);
    }

    private function fetchClinicalFeaturesAllTimeSeasonality(StatisticsContext $context): AllocationsOverTimeSeries
    {
        [$labels, $monthKeys] = $this->buildCalendarMonthOfYearAxis();
        $start = $this->effectiveAllTimeQueryStart($context);
        $hospitalIds = $this->hospitalIdsOrNull($context);
        $buckets = $this->projectionRepository->bucketClinicalFeaturesByCalendarMonth($start, null, $hospitalIds);

        return $this->mapClinicalFeaturesBucketsToSeries($labels, $monthKeys, $buckets);
    }

    private function fetchClinicalFeaturesDailyInMonth(StatisticsContext $context): AllocationsOverTimeSeries
    {
        [$labels, $monthKeys, $start, $toExclusive] = $this->buildDailyAxis($context);
        $hospitalIds = $this->hospitalIdsOrNull($context);
        $buckets = $this->projectionRepository->bucketClinicalFeaturesByDay($start, $toExclusive, $hospitalIds);

        return $this->mapClinicalFeaturesBucketsToSeries($labels, $monthKeys, $buckets);
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function buildRolling12MonthAxis(): array
    {
        $currentMonth = new \DateTimeImmutable('first day of this month 00:00:00');
        $start = StatisticsPeriod::overviewPeriodStart();

        $monthKeys = [];
        $labels = [];
        $cursor = $start;
        while ($cursor <= $currentMonth) {
            $monthKeys[] = $cursor->format('Y-m');
            $labels[] = $cursor->format('M');
            $cursor = $cursor->modify('+1 month');
        }

        return [$labels, $monthKeys];
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: \DateTimeImmutable, 3: \DateTimeImmutable}
     */
    private function buildBoundedMonthAxis(StatisticsContext $context): array
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $start = $bounds->from;
        $toExclusive = $bounds->toExclusive;
        \assert($toExclusive instanceof \DateTimeImmutable);

        $monthKeys = [];
        $labels = [];
        $cursor = $start->modify('first day of this month')->setTime(0, 0, 0);
        while ($cursor < $toExclusive) {
            $monthKeys[] = $cursor->format('Y-m');
            $labels[] = $cursor->format('M');
            $cursor = $cursor->modify('+1 month');
        }

        if ([] === $monthKeys) {
            $monthKeys[] = $start->format('Y-m');
            $labels[] = $start->format('M');
        }

        return [$labels, $monthKeys, $start, $toExclusive];
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function buildCalendarMonthOfYearAxis(): array
    {
        $monthKeys = [];
        $labels = [];
        for ($m = 1; $m <= 12; ++$m) {
            $monthKeys[] = sprintf('cal-%02d', $m);
            $labels[] = new \DateTimeImmutable(sprintf('2000-%02d-01', $m))->format('M');
        }

        return [$labels, $monthKeys];
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: \DateTimeImmutable, 3: \DateTimeImmutable}
     */
    private function buildDailyAxis(StatisticsContext $context): array
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $start = $bounds->from;
        $toExclusive = $bounds->toExclusive;
        \assert($toExclusive instanceof \DateTimeImmutable);

        $monthKeys = [];
        $labels = [];
        for ($d = $start; $d < $toExclusive; $d = $d->modify('+1 day')) {
            $monthKeys[] = $d->format('Y-m-d');
            $labels[] = $d->format('j');
        }

        if ([] === $monthKeys) {
            $monthKeys[] = $start->format('Y-m-d');
            $labels[] = $start->format('j');
        }

        return [$labels, $monthKeys, $start, $toExclusive];
    }

    private function effectiveAllTimeQueryStart(StatisticsContext $context): \DateTimeImmutable
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $start = $bounds->from;
        if ($bounds->toExclusive instanceof \DateTimeImmutable) {
            throw new \LogicException('all_time expects open-ended upper bound.');
        }

        $earliest = $this->projectionRepository->getEarliestCreatedAt();
        if ($earliest instanceof \DateTimeImmutable) {
            $firstMonth = $earliest->modify('first day of this month')->setTime(0, 0, 0);
            if ($firstMonth > $start) {
                $start = $firstMonth;
            }
        }

        return $start;
    }

    /**
     * @param list<string>                                                  $labels
     * @param list<string>                                                  $monthKeys
     * @param array<string, array{cathlab: int, resus: int, with_any: int}> $buckets
     */
    private function mapResourcesRequiredBucketsToSeries(array $labels, array $monthKeys, array $buckets): AllocationsOverTimeSeries
    {
        $cathlab = [];
        $resus = [];
        $withAny = [];
        foreach ($monthKeys as $mk) {
            $cell = $buckets[$mk] ?? null;
            $cathlab[] = \is_array($cell) ? ($cell['cathlab'] ?? 0) : 0;
            $resus[] = \is_array($cell) ? ($cell['resus'] ?? 0) : 0;
            $withAny[] = \is_array($cell) ? ($cell['with_any'] ?? 0) : 0;
        }

        return new AllocationsOverTimeSeries($labels, $monthKeys, [
            new AllocationsOverTimeSeriesSegment('cathlab', 'statistics.distribution.dim.requires_cathlab', $cathlab),
            new AllocationsOverTimeSeriesSegment('resus', 'statistics.distribution.dim.requires_resus', $resus),
        ], $withAny);
    }

    /**
     * @param list<string>                                                                                                                    $labels
     * @param list<string>                                                                                                                    $monthKeys
     * @param array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}> $buckets
     */
    private function mapClinicalFeaturesBucketsToSeries(array $labels, array $monthKeys, array $buckets): AllocationsOverTimeSeries
    {
        $segments = [];
        foreach (self::CLINICAL_FEATURE_SEGMENT_DEFINITIONS as $def) {
            $values = [];
            foreach ($monthKeys as $mk) {
                $cell = $buckets[$mk] ?? null;
                $values[] = \is_array($cell) ? ($cell[$def['key']] ?? 0) : 0;
            }
            $segments[] = new AllocationsOverTimeSeriesSegment($def['key'], $def['labelKey'], $values);
        }

        $withAny = [];
        foreach ($monthKeys as $mk) {
            $cell = $buckets[$mk] ?? null;
            $withAny[] = \is_array($cell) ? ($cell['with_any'] ?? 0) : 0;
        }

        return new AllocationsOverTimeSeries($labels, $monthKeys, $segments, $withAny);
    }

    /**
     * @param list<string>   $labels
     * @param list<string>   $monthKeys
     * @param list<int>|null $hospitalIds
     */
    private function fetchTotalRolling(array $labels, array $monthKeys, ?array $hospitalIds): AllocationsOverTimeSeries
    {
        $raw = $this->projectionRepository->countByMonthInPeriod(StatisticsPeriod::overviewPeriodStart(), null, $hospitalIds);
        $counts = $this->mapMonthlyCounts($raw, $monthKeys);

        return new AllocationsOverTimeSeries($labels, $monthKeys, [
            new AllocationsOverTimeSeriesSegment('total', 'stats.analysis.table.count', $counts),
        ]);
    }

    /**
     * @param list<string>   $labels
     * @param list<string>   $monthKeys
     * @param list<int>|null $hospitalIds
     */
    private function fetchGenderRolling(array $labels, array $monthKeys, ?array $hospitalIds): AllocationsOverTimeSeries
    {
        $buckets = $this->projectionRepository->bucketByMonthAndGender(StatisticsPeriod::overviewPeriodStart(), null, $hospitalIds);

        return $this->mapBucketsToSeries($labels, $monthKeys, $buckets, StatisticsAnalysisDimension::Gender);
    }

    /**
     * @param list<string>   $labels
     * @param list<string>   $monthKeys
     * @param list<int>|null $hospitalIds
     */
    private function fetchUrgencyRolling(array $labels, array $monthKeys, ?array $hospitalIds): AllocationsOverTimeSeries
    {
        $buckets = $this->projectionRepository->bucketByMonthAndUrgency(StatisticsPeriod::overviewPeriodStart(), null, $hospitalIds);

        return $this->mapBucketsToSeries($labels, $monthKeys, $buckets, StatisticsAnalysisDimension::Urgency);
    }

    private function fetchBoundedYearMonths(StatisticsContext $context, StatisticsAnalysisDimension $dimension): AllocationsOverTimeSeries
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $start = $bounds->from;
        $toExclusive = $bounds->toExclusive;
        \assert($toExclusive instanceof \DateTimeImmutable);

        $monthKeys = [];
        $labels = [];
        $cursor = $start->modify('first day of this month')->setTime(0, 0, 0);
        while ($cursor < $toExclusive) {
            $monthKeys[] = $cursor->format('Y-m');
            $labels[] = $cursor->format('M');
            $cursor = $cursor->modify('+1 month');
        }

        if ([] === $monthKeys) {
            $monthKeys[] = $start->format('Y-m');
            $labels[] = $start->format('M');
        }

        $hospitalIds = $this->hospitalIdsOrNull($context);

        return match ($dimension) {
            StatisticsAnalysisDimension::Total => $this->fetchTotalInRange($labels, $monthKeys, $start, $toExclusive, $hospitalIds),
            StatisticsAnalysisDimension::Gender => $this->fetchGenderInRange($labels, $monthKeys, $start, $toExclusive, $hospitalIds),
            StatisticsAnalysisDimension::Urgency => $this->fetchUrgencyInRange($labels, $monthKeys, $start, $toExclusive, $hospitalIds),
            StatisticsAnalysisDimension::Features,
            StatisticsAnalysisDimension::Resources => throw new \LogicException('Features and Resources must not reach bounded-period fetch (handled at start of fetch()).'),
        };
    }

    private function fetchBoundedDailyInMonth(StatisticsContext $context, StatisticsAnalysisDimension $dimension): AllocationsOverTimeSeries
    {
        [$labels, $monthKeys, $start, $toExclusive] = $this->buildDailyAxis($context);
        $hospitalIds = $this->hospitalIdsOrNull($context);

        return match ($dimension) {
            StatisticsAnalysisDimension::Total => $this->fetchTotalDaily($labels, $monthKeys, $start, $toExclusive, $hospitalIds),
            StatisticsAnalysisDimension::Gender => $this->fetchGenderDaily($labels, $monthKeys, $start, $toExclusive, $hospitalIds),
            StatisticsAnalysisDimension::Urgency => $this->fetchUrgencyDaily($labels, $monthKeys, $start, $toExclusive, $hospitalIds),
            StatisticsAnalysisDimension::Features,
            StatisticsAnalysisDimension::Resources => throw new \LogicException('Features and Resources must not reach bounded-period fetch (handled at start of fetch()).'),
        };
    }

    /**
     * @param list<string>   $labels
     * @param list<string>   $monthKeys
     * @param list<int>|null $hospitalIds
     */
    private function fetchTotalDaily(
        array $labels,
        array $monthKeys,
        \DateTimeImmutable $start,
        \DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
    ): AllocationsOverTimeSeries {
        $map = $this->projectionRepository->countByDayInPeriod($start, $toExclusive, $hospitalIds);
        $counts = [];
        foreach ($monthKeys as $mk) {
            $counts[] = $map[$mk] ?? 0;
        }

        return new AllocationsOverTimeSeries($labels, $monthKeys, [
            new AllocationsOverTimeSeriesSegment('total', 'stats.analysis.table.count', $counts),
        ]);
    }

    /**
     * @param list<string>   $labels
     * @param list<string>   $monthKeys
     * @param list<int>|null $hospitalIds
     */
    private function fetchGenderDaily(
        array $labels,
        array $monthKeys,
        \DateTimeImmutable $start,
        \DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
    ): AllocationsOverTimeSeries {
        $buckets = $this->projectionRepository->bucketByDayAndGender($start, $toExclusive, $hospitalIds);

        return $this->mapBucketsToSeries($labels, $monthKeys, $buckets, StatisticsAnalysisDimension::Gender);
    }

    /**
     * @param list<string>   $labels
     * @param list<string>   $monthKeys
     * @param list<int>|null $hospitalIds
     */
    private function fetchUrgencyDaily(
        array $labels,
        array $monthKeys,
        \DateTimeImmutable $start,
        \DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
    ): AllocationsOverTimeSeries {
        $buckets = $this->projectionRepository->bucketByDayAndUrgency($start, $toExclusive, $hospitalIds);

        return $this->mapBucketsToSeries($labels, $monthKeys, $buckets, StatisticsAnalysisDimension::Urgency);
    }

    /**
     * @param list<string>   $labels
     * @param list<string>   $monthKeys
     * @param list<int>|null $hospitalIds
     */
    private function fetchTotalInRange(
        array $labels,
        array $monthKeys,
        \DateTimeImmutable $start,
        \DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
    ): AllocationsOverTimeSeries {
        $raw = $this->projectionRepository->countByMonthInPeriod($start, $toExclusive, $hospitalIds);
        $counts = $this->mapMonthlyCounts($raw, $monthKeys);

        return new AllocationsOverTimeSeries($labels, $monthKeys, [
            new AllocationsOverTimeSeriesSegment('total', 'stats.analysis.table.count', $counts),
        ]);
    }

    /**
     * @param list<string>   $labels
     * @param list<string>   $monthKeys
     * @param list<int>|null $hospitalIds
     */
    private function fetchGenderInRange(
        array $labels,
        array $monthKeys,
        \DateTimeImmutable $start,
        \DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
    ): AllocationsOverTimeSeries {
        $buckets = $this->projectionRepository->bucketByMonthAndGender($start, $toExclusive, $hospitalIds);

        return $this->mapBucketsToSeries($labels, $monthKeys, $buckets, StatisticsAnalysisDimension::Gender);
    }

    /**
     * @param list<string>   $labels
     * @param list<string>   $monthKeys
     * @param list<int>|null $hospitalIds
     */
    private function fetchUrgencyInRange(
        array $labels,
        array $monthKeys,
        \DateTimeImmutable $start,
        \DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
    ): AllocationsOverTimeSeries {
        $buckets = $this->projectionRepository->bucketByMonthAndUrgency($start, $toExclusive, $hospitalIds);

        return $this->mapBucketsToSeries($labels, $monthKeys, $buckets, StatisticsAnalysisDimension::Urgency);
    }

    private function fetchAllTime(StatisticsContext $context, StatisticsAnalysisDimension $dimension): AllocationsOverTimeSeries
    {
        [$labels, $monthKeys] = $this->buildCalendarMonthOfYearAxis();
        $start = $this->effectiveAllTimeQueryStart($context);
        $hospitalIds = $this->hospitalIdsOrNull($context);

        return match ($dimension) {
            StatisticsAnalysisDimension::Total => $this->fetchTotalAllTimeSeasonality($labels, $monthKeys, $start, $hospitalIds),
            StatisticsAnalysisDimension::Gender => $this->fetchGenderAllTimeSeasonality($labels, $monthKeys, $start, $hospitalIds),
            StatisticsAnalysisDimension::Urgency => $this->fetchUrgencyAllTimeSeasonality($labels, $monthKeys, $start, $hospitalIds),
            StatisticsAnalysisDimension::Features,
            StatisticsAnalysisDimension::Resources => throw new \LogicException('Features and Resources must not reach all-time fetch (handled at start of fetch()).'),
        };
    }

    /**
     * @param list<string>   $labels
     * @param list<string>   $monthKeys
     * @param list<int>|null $hospitalIds
     */
    private function fetchTotalAllTimeSeasonality(array $labels, array $monthKeys, \DateTimeImmutable $start, ?array $hospitalIds): AllocationsOverTimeSeries
    {
        $map = $this->projectionRepository->countByCalendarMonthInPeriod($start, null, $hospitalIds);
        $counts = [];
        foreach ($monthKeys as $mk) {
            $counts[] = $map[$mk] ?? 0;
        }

        return new AllocationsOverTimeSeries($labels, $monthKeys, [
            new AllocationsOverTimeSeriesSegment('total', 'stats.analysis.table.count', $counts),
        ]);
    }

    /**
     * @param list<string>   $labels
     * @param list<string>   $monthKeys
     * @param list<int>|null $hospitalIds
     */
    private function fetchGenderAllTimeSeasonality(array $labels, array $monthKeys, \DateTimeImmutable $start, ?array $hospitalIds): AllocationsOverTimeSeries
    {
        $buckets = $this->projectionRepository->bucketByCalendarMonthAndGender($start, null, $hospitalIds);

        return $this->mapBucketsToSeries($labels, $monthKeys, $buckets, StatisticsAnalysisDimension::Gender);
    }

    /**
     * @param list<string>   $labels
     * @param list<string>   $monthKeys
     * @param list<int>|null $hospitalIds
     */
    private function fetchUrgencyAllTimeSeasonality(array $labels, array $monthKeys, \DateTimeImmutable $start, ?array $hospitalIds): AllocationsOverTimeSeries
    {
        $buckets = $this->projectionRepository->bucketByCalendarMonthAndUrgency($start, null, $hospitalIds);

        return $this->mapBucketsToSeries($labels, $monthKeys, $buckets, StatisticsAnalysisDimension::Urgency);
    }

    /**
     * @param list<string>                      $labels
     * @param list<string>                      $monthKeys
     * @param array<string, array<string, int>> $buckets
     */
    private function mapBucketsToSeries(
        array $labels,
        array $monthKeys,
        array $buckets,
        StatisticsAnalysisDimension $dimension,
    ): AllocationsOverTimeSeries {
        $defs = $this->segmentDefinitions($dimension);
        $segments = [];
        foreach ($defs as $def) {
            $values = [];
            foreach ($monthKeys as $mk) {
                $values[] = $buckets[$mk][$def['key']] ?? 0;
            }
            $segments[] = new AllocationsOverTimeSeriesSegment($def['key'], $def['labelKey'], $values);
        }

        return new AllocationsOverTimeSeries($labels, $monthKeys, $segments);
    }

    /**
     * @return list<array{key: string, labelKey: string}>
     */
    private function segmentDefinitions(StatisticsAnalysisDimension $dimension): array
    {
        return match ($dimension) {
            StatisticsAnalysisDimension::Total => [
                ['key' => 'total', 'labelKey' => 'stats.analysis.table.count'],
            ],
            StatisticsAnalysisDimension::Gender => array_map(
                static fn (AllocationGender $case): array => [
                    'key' => $case->value,
                    'labelKey' => $case->label(),
                ],
                AllocationGender::cases(),
            ),
            StatisticsAnalysisDimension::Urgency => array_map(
                static fn (AllocationUrgency $case): array => [
                    'key' => (string) $case->value,
                    'labelKey' => self::URGENCY_SHORT_LABEL_KEYS[$case->value],
                ],
                AllocationUrgency::cases(),
            ),
            StatisticsAnalysisDimension::Features,
            StatisticsAnalysisDimension::Resources => throw new \LogicException('Features and Resources are not segmented like Gender/Urgency.'),
        };
    }

    /**
     * @param array<int, array{year: int, month: int, count: int}> $rawRows
     * @param list<string>                                         $monthKeys
     *
     * @return list<int>
     */
    private function mapMonthlyCounts(array $rawRows, array $monthKeys): array
    {
        $base = array_fill_keys($monthKeys, 0);

        foreach ($rawRows as $row) {
            $key = sprintf('%04d-%02d', $row['year'], $row['month']);
            if (\array_key_exists($key, $base)) {
                $base[$key] = $row['count'];
            }
        }

        return array_values($base);
    }
}
