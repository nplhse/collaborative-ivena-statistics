<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\Monthly;

use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Kpi\Infrastructure\Repository\KpiDailyRepository;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\Insights\HospitalInsight;
use App\Statistics\Application\Insights\HospitalInsightSelector;
use App\Statistics\Application\Overview\OverviewPeriodComparisonService;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Application\SummarizedReport\Monthly\Dto\MonthlyReportSegment;
use App\Statistics\Application\SummarizedReport\Monthly\Dto\MonthlyReportTopRow;
use App\Statistics\Application\SummarizedReport\Monthly\Dto\MonthlyReportView;
use App\Statistics\Application\TopDiagnosesQuery;
use App\Statistics\Application\TopEntityQuery;
use App\Statistics\Benchmarking\Application\BenchmarkCriteriaFactory;
use App\Statistics\Benchmarking\Application\BenchmarkReportService;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Infrastructure\Query\Overview\GetOverviewDashboardMetricsQuery;
use App\Statistics\Infrastructure\Query\Overview\OverviewQueryCriteria;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MonthlyReportBuilder
{
    private const int TOP_LIMIT = 5;

    /** @var array<string, string> */
    private const array GENDER_BAR_CLASSES = [
        'M' => 'bg-primary',
        'F' => 'bg-pink',
        'X' => 'bg-purple',
    ];

    /** @var array<int, string> */
    private const array URGENCY_BAR_CLASSES = [
        1 => 'bg-red',
        2 => 'bg-yellow',
        3 => 'bg-green',
    ];

    public function __construct(
        private MonthlyReportPeriodResolver $periodResolver,
        private StatisticsScopeResolver $scopeResolver,
        private ProjectionTimeSeriesQuery $timeSeriesQuery,
        private GetOverviewDashboardMetricsQuery $overviewMetricsQuery,
        private TopDiagnosesQuery $topDiagnosesQuery,
        private TopEntityQuery $topEntityQuery,
        private BenchmarkCriteriaFactory $benchmarkCriteriaFactory,
        private BenchmarkReportService $benchmarkReportService,
        private HospitalInsightSelector $insightSelector,
        private KpiDailyRepository $kpiDailyRepository,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function build(
        StatisticsContext $context,
        string $locale,
        ?\DateTimeImmutable $referenceDate = null,
        ?int $year = null,
        ?int $month = null,
    ): MonthlyReportView {
        $period = $this->periodResolver->resolve($referenceDate, $year, $month);
        $periodLabel = $this->formatMonthYear($period['year'], $period['month'], $locale);

        $monthFilter = $this->filterForMonth($context->filter, $period['year'], $period['month']);
        $monthContext = new StatisticsContext($context->user, $monthFilter, drawerFilter: $context->drawerFilter);

        $scopeCriteria = $this->scopeResolver->resolveCriteria($monthContext);
        $bounds = StatisticsPeriodResolver::resolve($monthFilter);
        $overviewMetrics = ($this->overviewMetricsQuery)(
            OverviewQueryCriteria::fromPeriodBounds(
                $bounds,
                $scopeCriteria->hospitalIds,
                $scopeCriteria->dispatchAreaId,
            ),
        );

        $allocationCount = $overviewMetrics->scopedTotal;
        $hasData = $allocationCount > 0;

        $previousCount = $this->timeSeriesQuery->countCreatedInPeriod(
            $period['previousMonthStart'],
            $period['previousMonthEndExclusive'],
            $scopeCriteria->hospitalIds,
            $context->drawerFilter,
            $scopeCriteria->dispatchAreaId,
        );
        $yearAgoCount = $this->timeSeriesQuery->countCreatedInPeriod(
            $period['yearAgoMonthStart'],
            $period['yearAgoMonthEndExclusive'],
            $scopeCriteria->hospitalIds,
            $context->drawerFilter,
            $scopeCriteria->dispatchAreaId,
        );

        $mom = $hasData
            ? OverviewPeriodComparisonService::relativePercentChange($allocationCount, $previousCount)
            : null;
        $yoy = $hasData
            ? OverviewPeriodComparisonService::relativePercentChange($allocationCount, $yearAgoCount)
            : null;

        $withPhysicianPercent = $hasData
            ? round(100 * $overviewMetrics->withPhysician / $allocationCount, 1)
            : 0.0;
        $resusPercent = $hasData
            ? round(100 * $overviewMetrics->resus / $allocationCount, 1)
            : 0.0;

        $topDiagnoses = $hasData ? $this->mapTopRows(
            array_map(
                static fn (array $row): array => [
                    'label' => $row['label'],
                    'count' => $row['count'],
                ],
                $this->topDiagnosesQuery->fetch($monthContext, self::TOP_LIMIT, $allocationCount)['rows'],
            ),
            $allocationCount,
        ) : [];
        $topOccasions = $hasData ? $this->mapTopRows(
            $this->topEntityQuery->fetch($monthContext, self::TOP_LIMIT, 'occasionId', Occasion::class, $allocationCount)['rows'],
            $allocationCount,
        ) : [];
        $topDepartments = $hasData ? $this->mapTopRows(
            $this->topEntityQuery->fetch($monthContext, self::TOP_LIMIT, 'departmentId', Department::class, $allocationCount)['rows'],
            $allocationCount,
        ) : [];

        $previousMonthLabel = $this->formatMonthYear(
            $period['navigationPreviousYear'],
            $period['navigationPreviousMonth'],
            $locale,
        );
        $benchmarkingUrl = $this->benchmarkingUrl(
            $monthFilter,
            $period['year'],
            $period['month'],
            $period['navigationPreviousYear'],
            $period['navigationPreviousMonth'],
        );
        $insights = $hasData
            ? $this->buildInsights(
                $monthContext,
                $monthFilter,
                $period,
                $mom,
                $yoy,
                $periodLabel,
                $previousMonthLabel,
                $benchmarkingUrl,
                $locale,
            )
            : [];

        $urgencySegments = [];
        $genderSegments = [];
        $withPhysicianMomPercent = null;
        $resusMomPercent = null;
        $medianTransportMomMinutes = null;
        if ($hasData) {
            $previousOverviewMetrics = ($this->overviewMetricsQuery)(
                new OverviewQueryCriteria(
                    $period['previousMonthStart'],
                    $period['previousMonthEndExclusive'],
                    $scopeCriteria->hospitalIds,
                    $scopeCriteria->dispatchAreaId,
                ),
            );
            $urgencySegments = $this->urgencySegments(
                $overviewMetrics->urgencyCounts,
                $allocationCount,
                $previousOverviewMetrics->urgencyCounts,
                $previousOverviewMetrics->scopedTotal,
                $locale,
            );
            $genderSegments = $this->genderSegments(
                $overviewMetrics->genderCounts,
                $allocationCount,
                $previousOverviewMetrics->genderCounts,
                $previousOverviewMetrics->scopedTotal,
                $locale,
            );

            if ($previousOverviewMetrics->scopedTotal > 0) {
                $previousWithPhysicianPercent = round(
                    100 * $previousOverviewMetrics->withPhysician / $previousOverviewMetrics->scopedTotal,
                    1,
                );
                $previousResusPercent = round(
                    100 * $previousOverviewMetrics->resus / $previousOverviewMetrics->scopedTotal,
                    1,
                );
                $withPhysicianMomPercent = round($withPhysicianPercent - $previousWithPhysicianPercent, 1);
                $resusMomPercent = round($resusPercent - $previousResusPercent, 1);
            }

            if (
                null !== $overviewMetrics->medianTransportMinutes
                && null !== $previousOverviewMetrics->medianTransportMinutes
            ) {
                $medianTransportMomMinutes = round(
                    $overviewMetrics->medianTransportMinutes - $previousOverviewMetrics->medianTransportMinutes,
                    1,
                );
            }
        }

        $dashboardParams = $this->periodQueryParams($monthFilter, $period['year'], $period['month']);
        $dashboardUrl = $this->urlGenerator->generate('app_stats_dashboard', $dashboardParams);

        return new MonthlyReportView(
            periodLabel: $periodLabel,
            year: $period['year'],
            month: $period['month'],
            hasData: $hasData,
            allocationCount: $allocationCount,
            allocationMomPercent: $mom,
            allocationYoyPercent: $yoy,
            withPhysicianPercent: $withPhysicianPercent,
            withPhysicianMomPercent: $withPhysicianMomPercent,
            medianTransportMinutes: $hasData ? $overviewMetrics->medianTransportMinutes : null,
            medianTransportMomMinutes: $medianTransportMomMinutes,
            resusPercent: $resusPercent,
            resusMomPercent: $resusMomPercent,
            urgencySegments: $urgencySegments,
            genderSegments: $genderSegments,
            topDiagnoses: $topDiagnoses,
            topOccasions: $topOccasions,
            topDepartments: $topDepartments,
            insights: $insights,
            dailyChart: $this->buildDailyChart(
                $period['monthStart'],
                $period['monthEndExclusive'],
                $scopeCriteria->hospitalIds,
                $locale,
            ),
            importCreateUrl: $this->urlGenerator->generate('app_import_new'),
            dashboardUrl: $dashboardUrl,
            benchmarkingUrl: $benchmarkingUrl,
            previousMonthLabel: $previousMonthLabel,
            previousYear: $period['navigationPreviousYear'],
            previousMonth: $period['navigationPreviousMonth'],
            nextMonthLabel: $period['navigationNextEnabled'] && null !== $period['navigationNextYear'] && null !== $period['navigationNextMonth']
                ? $this->formatMonthYear($period['navigationNextYear'], $period['navigationNextMonth'], $locale)
                : null,
            nextYear: $period['navigationNextYear'],
            nextMonth: $period['navigationNextMonth'],
            nextEnabled: $period['navigationNextEnabled'],
        );
    }

    /**
     * @param array{
     *     year: int,
     *     month: int,
     *     monthStart: \DateTimeImmutable,
     *     monthEndExclusive: \DateTimeImmutable,
     *     previousMonthStart: \DateTimeImmutable,
     *     previousMonthEndExclusive: \DateTimeImmutable,
     *     yearAgoMonthStart: \DateTimeImmutable,
     *     yearAgoMonthEndExclusive: \DateTimeImmutable,
     *     latestCompletedMonthStart: \DateTimeImmutable,
     *     navigationPreviousYear: int,
     *     navigationPreviousMonth: int,
     *     navigationNextYear: ?int,
     *     navigationNextMonth: ?int,
     *     navigationNextEnabled: bool,
     *     referenceDate: \DateTimeImmutable
     * } $period
     *
     * @return list<HospitalInsight>
     */
    private function buildInsights(
        StatisticsContext $monthContext,
        StatisticsFilter $monthFilter,
        array $period,
        ?float $mom,
        ?float $yoy,
        string $periodLabel,
        string $previousMonthLabel,
        string $benchmarkingUrl,
        string $locale,
    ): array {
        $previousFilter = $this->filterForMonth(
            $monthFilter,
            $period['navigationPreviousYear'],
            $period['navigationPreviousMonth'],
        );
        $selfReport = $this->benchmarkReportService->buildForOverview(
            $this->benchmarkCriteriaFactory->create($monthContext, $previousFilter),
        );

        $physicianMetric = $this->findMetric($selfReport->kpiMetrics, BenchmarkMetricKey::WithPhysician);
        $resusMetric = $this->findMetric($selfReport->kpiMetrics, BenchmarkMetricKey::Resus);

        return $this->insightSelector->select(
            $mom,
            $yoy,
            array_values(array_filter([$physicianMetric, $resusMetric])),
            $selfReport->indicationMix,
            $this->rejectionRateDelta(
                $monthFilter->hospitalId,
                $period['monthStart'],
                $period['monthEndExclusive'],
                $period['previousMonthStart'],
            ),
            $benchmarkingUrl,
            $previousMonthLabel,
            $periodLabel,
            $locale,
        );
    }

    /**
     * @param list<BenchmarkMetric> $metrics
     */
    private function findMetric(array $metrics, BenchmarkMetricKey $key): ?BenchmarkMetric
    {
        foreach ($metrics as $metric) {
            if ($metric->key === $key) {
                return $metric;
            }
        }

        return null;
    }

    private function rejectionRateDelta(
        ?int $hospitalId,
        \DateTimeImmutable $monthStart,
        \DateTimeImmutable $monthEndExclusive,
        \DateTimeImmutable $previousMonthStart,
    ): ?float {
        if (null === $hospitalId) {
            return null;
        }

        $current = $this->kpiDailyRepository->rejectionRateForHospitalInRange(
            $hospitalId,
            $monthStart,
            $monthEndExclusive,
        );
        $previous = $this->kpiDailyRepository->rejectionRateForHospitalInRange(
            $hospitalId,
            $previousMonthStart,
            $monthStart,
        );
        if (null === $current || null === $previous) {
            return null;
        }

        return round($current - $previous, 1);
    }

    private function benchmarkingUrl(
        StatisticsFilter $filter,
        int $year,
        int $month,
        int $previousYear,
        int $previousMonth,
    ): string {
        $params = $this->periodQueryParams($filter, $year, $month);
        $params[StatisticsQueryKeys::COMPARISON_SCOPE] = $filter->scope->value;
        $params[StatisticsQueryKeys::COMPARISON_PERIOD] = StatisticsFilterPeriod::Month->value;
        $params[StatisticsQueryKeys::COMPARISON_YEAR] = $previousYear;
        $params[StatisticsQueryKeys::COMPARISON_MONTH] = $previousMonth;

        if (null !== $filter->hospitalId) {
            $params[StatisticsQueryKeys::COMPARISON_HOSPITAL] = $filter->hospitalId;
        }
        if (null !== $filter->stateId) {
            $params[StatisticsQueryKeys::COMPARISON_STATE] = $filter->stateId;
        }
        if (null !== $filter->dispatchAreaId) {
            $params[StatisticsQueryKeys::COMPARISON_DISPATCH_AREA] = $filter->dispatchAreaId;
        }
        if ($filter->cohortType instanceof \App\Statistics\Application\Cohort\HospitalCohortKey) {
            $params[StatisticsQueryKeys::COMPARISON_COHORT] = $filter->cohortType->value();
        }

        return $this->urlGenerator->generate('app_stats_benchmarking', $params);
    }

    /**
     * @return array<string, int|string>
     */
    private function periodQueryParams(StatisticsFilter $filter, int $year, int $month): array
    {
        return array_filter([
            StatisticsQueryKeys::SCOPE => $filter->scope->value,
            StatisticsQueryKeys::HOSPITAL => $filter->hospitalId,
            StatisticsQueryKeys::STATE => $filter->stateId,
            StatisticsQueryKeys::DISPATCH_AREA => $filter->dispatchAreaId,
            StatisticsQueryKeys::COHORT => $filter->cohortType?->value(),
            StatisticsQueryKeys::PERIOD => StatisticsFilterPeriod::Month->value,
            StatisticsQueryKeys::YEAR => $year,
            StatisticsQueryKeys::MONTH => $month,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    private function filterForMonth(StatisticsFilter $filter, int $year, int $month): StatisticsFilter
    {
        return new StatisticsFilter(
            $filter->scope,
            $filter->hospitalId,
            $filter->cohortType,
            StatisticsFilterPeriod::Month,
            $year,
            $month,
            null,
            $filter->notice,
            $filter->requiresPublicRedirect,
            $filter->stateId,
            $filter->dispatchAreaId,
        );
    }

    /**
     * @param list<array{label: string, count: int, entityId?: ?int, publicId?: ?string}> $rows
     *
     * @return list<MonthlyReportTopRow>
     */
    private function mapTopRows(array $rows, int $total): array
    {
        $mapped = [];
        $rank = 1;
        foreach ($rows as $row) {
            $share = $total > 0 ? round(100 * $row['count'] / $total, 1) : 0.0;
            $mapped[] = new MonthlyReportTopRow(
                $rank,
                $row['label'],
                $row['count'],
                sprintf('%.1f%%', $share),
            );
            ++$rank;
        }

        return $mapped;
    }

    /**
     * @param array<int, int> $urgencyCounts
     * @param array<int, int> $previousUrgencyCounts
     *
     * @return list<MonthlyReportSegment>
     */
    private function urgencySegments(
        array $urgencyCounts,
        int $total,
        array $previousUrgencyCounts,
        int $previousTotal,
        string $locale,
    ): array {
        $segments = [];
        foreach (AllocationUrgency::cases() as $case) {
            $segments[] = $this->distributionSegment(
                $this->translator->trans($case->label(), [], 'messages', $locale),
                $urgencyCounts[$case->value] ?? 0,
                $total,
                $previousUrgencyCounts[$case->value] ?? 0,
                $previousTotal,
                self::URGENCY_BAR_CLASSES[$case->value] ?? 'bg-secondary',
            );
        }

        return $segments;
    }

    /**
     * @param array<string, int> $genderCounts
     * @param array<string, int> $previousGenderCounts
     *
     * @return list<MonthlyReportSegment>
     */
    private function genderSegments(
        array $genderCounts,
        int $total,
        array $previousGenderCounts,
        int $previousTotal,
        string $locale,
    ): array {
        $segments = [];
        foreach (AllocationGender::cases() as $case) {
            $segments[] = $this->distributionSegment(
                $this->translator->trans($case->label(), [], 'messages', $locale),
                $genderCounts[$case->value] ?? 0,
                $total,
                $previousGenderCounts[$case->value] ?? 0,
                $previousTotal,
                self::GENDER_BAR_CLASSES[$case->value] ?? 'bg-secondary',
            );
        }

        return $segments;
    }

    private function distributionSegment(
        string $label,
        int $count,
        int $total,
        int $previousCount,
        int $previousTotal,
        string $barClass,
    ): MonthlyReportSegment {
        $percent = $total > 0 ? round(100 * $count / $total, 1) : 0.0;
        $previousPercent = $previousTotal > 0
            ? round(100 * $previousCount / $previousTotal, 1)
            : null;

        return new MonthlyReportSegment(
            $label,
            $count,
            $percent,
            $barClass,
            null !== $previousPercent ? round($percent - $previousPercent, 1) : null,
        );
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array{
     *     chartType: string,
     *     labels: list<string>,
     *     counts: list<int>,
     *     valueLabel: string,
     *     xAxisLabel: string,
     *     yAxisLabel: string
     * }
     */
    private function buildDailyChart(
        \DateTimeImmutable $monthStart,
        \DateTimeImmutable $monthEndExclusive,
        ?array $hospitalIds,
        string $locale,
    ): array {
        $byDay = $this->timeSeriesQuery->countByDayInPeriod($monthStart, $monthEndExclusive, $hospitalIds);
        $labels = [];
        $counts = [];
        $cursor = $monthStart;
        while ($cursor < $monthEndExclusive) {
            $key = $cursor->format('Y-m-d');
            $labels[] = $cursor->format('j');
            $counts[] = $byDay[$key] ?? 0;
            $cursor = $cursor->modify('+1 day');
        }

        $allocationsLabel = $this->translator->trans(
            'stats.reports.monthly.kpi.allocations',
            [],
            'statistics',
            $locale,
        );
        $dayLabel = $this->translator->trans(
            'stats.reports.monthly.chart.day_axis',
            [],
            'statistics',
            $locale,
        );

        return [
            'chartType' => 'bar',
            'labels' => $labels,
            'counts' => $counts,
            'valueLabel' => $allocationsLabel,
            'xAxisLabel' => $dayLabel,
            'yAxisLabel' => $allocationsLabel,
        ];
    }

    private function formatMonthYear(int $year, int $month, string $locale): string
    {
        $date = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $formatted = \IntlDateFormatter::formatObject($date, 'LLLL yyyy', $locale);

        return false !== $formatted ? $formatted : sprintf('%04d-%02d', $year, $month);
    }
}
