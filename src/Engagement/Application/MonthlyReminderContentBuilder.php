<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Engagement\Application\Dto\MonthlyReminderContent;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Kpi\Infrastructure\Repository\KpiDailyRepository;
use App\Statistics\Application\ChartBucketMapper;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\Insights\HospitalInsight;
use App\Statistics\Application\Insights\HospitalInsightSelector;
use App\Statistics\Application\Insights\HospitalInsightTrend;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Infrastructure\Query\Overview\GetOverviewDashboardMetricsQuery;
use App\Statistics\Infrastructure\Query\Overview\OverviewQueryCriteria;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MonthlyReminderContentBuilder
{
    private const int MIN_ALLOCATIONS_PERSONALIZED = 20;

    private const int STALE_IMPORT_DAYS = 90;

    public function __construct(
        private MonthlyReminderPeriodResolver $periodResolver,
        private MonthlyReminderComparisonFilterFactory $comparisonFilterFactory,
        private MonthlyReminderSelfBenchmarkFactory $selfBenchmarkFactory,
        private ProjectionTimeSeriesQuery $timeSeriesQuery,
        private ImportRepository $importRepository,
        private GetOverviewDashboardMetricsQuery $overviewMetricsQuery,
        private MonthlyReminderChartBuilder $chartBuilder,
        private HospitalInsightSelector $insightSelector,
        private MonthlyReminderDistributionSegments $distributionSegments,
        private KpiDailyRepository $kpiDailyRepository,
        private ChartBucketMapper $chartBucketMapper,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function build(Hospital $hospital, ?\DateTimeImmutable $referenceDate, string $locale): MonthlyReminderContent
    {
        $period = $this->periodResolver->resolve($referenceDate);
        $chartLabels = array_map(
            fn (string $monthKey): string => $this->formatChartMonthLabel($monthKey, $locale),
            $period['chartMonthKeys'],
        );
        $hospitalId = (int) $hospital->getId();
        $hospitalIds = [$hospitalId];

        $reportingKey = sprintf('%04d-%02d', $period['reportingYear'], $period['reportingMonth']);
        $previousMonthStart = $period['reportingMonthStart']->modify('-1 month');
        $previousReportingKey = $previousMonthStart->format('Y-m');
        $yearAgoKey = $period['reportingMonthStart']->modify('-1 year')->format('Y-m');

        $chartAllocationRows = $this->timeSeriesQuery->countByMonthInPeriod(
            $period['chartStart'],
            null,
            $hospitalIds,
        );

        $allocationBuckets = $this->chartBucketMapper->monthRowsToBucketCounts($chartAllocationRows);
        $reportingAllocations = $allocationBuckets[$reportingKey] ?? 0;
        $previousAllocations = $allocationBuckets[$previousReportingKey] ?? 0;
        $yearAgoAllocations = $allocationBuckets[$yearAgoKey] ?? 0;

        $latestImport = $this->importRepository->findLatestSuccessfulByHospital($hospitalId);
        $daysSinceImport = $this->daysSinceImport($latestImport, $period['referenceDate']);
        $isStaleImport = null === $daysSinceImport || $daysSinceImport > self::STALE_IMPORT_DAYS;
        $isPersonalized = $reportingAllocations >= self::MIN_ALLOCATIONS_PERSONALIZED
            || (null !== $daysSinceImport && $daysSinceImport <= 60);

        $primaryFilter = $this->comparisonFilterFactory->createPrimaryFilter(
            $hospitalId,
            StatisticsFilterPeriod::Month,
            $period['reportingYear'],
            $period['reportingMonth'],
        );
        $selfReport = $this->selfBenchmarkFactory->build(
            $hospitalId,
            $period['reportingYear'],
            $period['reportingMonth'],
        );

        $overviewMetrics = ($this->overviewMetricsQuery)(
            OverviewQueryCriteria::fromPeriodBounds(
                StatisticsPeriodResolver::resolve($primaryFilter),
                $hospitalIds,
            ),
        );

        $withPhysicianPercent = $overviewMetrics->scopedTotal > 0
            ? round(100 * $overviewMetrics->withPhysician / $overviewMetrics->scopedTotal, 1)
            : 0.0;

        $physicianMetric = $this->findMetric($selfReport->kpiMetrics, BenchmarkMetricKey::WithPhysician);
        $transportMetric = $this->findMetric($selfReport->kpiMetrics, BenchmarkMetricKey::MedianTransport);
        $resusMetric = $this->findMetric($selfReport->kpiMetrics, BenchmarkMetricKey::Resus);

        $chartBars = $this->chartBuilder->build(
            $period['chartMonthKeys'],
            $chartLabels,
            $chartAllocationRows,
            $reportingKey,
        );
        $allocationSeries = array_map(static fn (Dto\MonthlyReminderChartBar $bar): int => $bar->allocationCount, $chartBars);
        $trendKey = $this->chartBuilder->summarizeTrend($allocationSeries);

        $submissionMonths = $this->importRepository->monthlySubmissionStatusForHospital(
            $hospitalId,
            $period['chartMonthKeys'],
            $period['chartStart'],
        );
        $completedMonths = count(array_filter($submissionMonths, static fn (string $s): bool => 'success' === $s));
        $totalMonths = \count($period['chartMonthKeys']);

        $rejectionDelta = $this->rejectionRateDelta(
            $hospitalId,
            $period['reportingMonthStart'],
            $period['reportingMonthEnd'],
            $previousMonthStart,
        );

        $importCreateUrl = $this->urlGenerator->generate('app_import_new', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $statisticsUrl = $this->urlGenerator->generate('app_stats_dashboard', [
            'scope' => StatisticsFilterScope::Hospital->value,
            'hospital' => $hospitalId,
            'period' => StatisticsFilterPeriod::Month->value,
            'year' => $period['reportingYear'],
            'month' => $period['reportingMonth'],
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        $selfBenchmarkUrl = $this->urlGenerator->generate('app_stats_benchmarking', [
            'scope' => StatisticsFilterScope::Hospital->value,
            'hospital' => $hospitalId,
            'period' => StatisticsFilterPeriod::Month->value,
            'year' => $period['reportingYear'],
            'month' => $period['reportingMonth'],
            StatisticsQueryKeys::COMPARISON_SCOPE => StatisticsFilterScope::Hospital->value,
            StatisticsQueryKeys::COMPARISON_HOSPITAL => $hospitalId,
            StatisticsQueryKeys::COMPARISON_PERIOD => StatisticsFilterPeriod::All->value,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        $benchmarkingUrl = $this->urlGenerator->generate('app_stats_benchmarking', [
            'scope' => StatisticsFilterScope::Hospital->value,
            'hospital' => $hospitalId,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        $notificationsSettingsUrl = $this->urlGenerator->generate(
            'app_settings_notifications',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $baselinePeriodLabel = $this->trans('monthly_reminder.baseline.period_label', [], $locale);
        $reportingPeriodLabel = $this->formatMonthYear($period['reportingYear'], $period['reportingMonth'], $locale);

        $allocationMom = $this->chartBuilder->percentChange($reportingAllocations, $previousAllocations);
        $allocationYoy = $this->chartBuilder->percentChange($reportingAllocations, $yearAgoAllocations);

        $insights = $isPersonalized
            ? $this->insightSelector->select(
                $allocationMom,
                $allocationYoy,
                array_values(array_filter([$physicianMetric, $resusMetric])),
                $selfReport->indicationMix,
                $rejectionDelta,
                $selfBenchmarkUrl,
                $baselinePeriodLabel,
                $reportingPeriodLabel,
                $locale,
            )
            : $this->fallbackInsights($benchmarkingUrl, $locale);

        $platformData = $isPersonalized ? [] : $this->platformFallbackData(
            $period,
            $reportingKey,
            $previousReportingKey,
        );

        $uploadMonthLabel = $this->formatMonthYear($period['uploadYear'], $period['uploadMonth'], $locale);

        $preheader = $this->trans('monthly_reminder.preheader', [
            'period' => $reportingPeriodLabel,
            'count' => $reportingAllocations,
            'delta' => null !== $allocationMom ? $this->formatSignedPercent($allocationMom) : '—',
            'upload_month' => $uploadMonthLabel,
        ], $locale);

        return new MonthlyReminderContent(
            hospitalName: (string) $hospital->getName(),
            reportingPeriodLabel: $reportingPeriodLabel,
            uploadMonthLabel: $uploadMonthLabel,
            preheader: $preheader,
            isPersonalized: $isPersonalized,
            allocationCount: $reportingAllocations,
            allocationMomPercent: $allocationMom,
            lastImportLabel: $this->formatLastImportLabel($latestImport, $locale),
            lastImportStale: $isStaleImport,
            withPhysicianPercent: $withPhysicianPercent,
            withPhysicianBaselineDeltaPp: $physicianMetric?->absoluteDelta,
            baselinePeriodLabel: $baselinePeriodLabel,
            medianTransportMinutes: $transportMetric instanceof \App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric ? $transportMetric->primaryValue : 0.0,
            medianTransportBaselineDeltaMinutes: $transportMetric?->absoluteDelta,
            trendSummary: '' !== $trendKey ? $this->trans($trendKey, [
                'percent' => number_format(abs($this->averageMonthlyChange($allocationSeries)), 1, '.', ''),
            ], $locale) : '',
            chartBars: $chartBars,
            urgencySegments: $this->distributionSegments->urgencySegments(
                $overviewMetrics->urgencyCounts,
                $overviewMetrics->scopedTotal,
                $locale,
            ),
            urgencyBenchmarkNote: $this->urgencyBenchmarkNote($selfReport, $baselinePeriodLabel, $locale),
            genderSegments: $this->distributionSegments->genderSegments(
                $overviewMetrics->genderCounts,
                $overviewMetrics->scopedTotal,
                $locale,
            ),
            insights: $insights,
            submissionMonthsCompleted: $completedMonths,
            submissionMonthsTotal: $totalMonths,
            submissionProgressPercent: $totalMonths > 0 ? (int) round(100 * $completedMonths / $totalMonths) : 0,
            submissionMonths: $submissionMonths,
            longestSubmissionGapLabel: $this->longestGapLabel($submissionMonths, $period['chartMonthKeys'], $locale),
            importCreateUrl: $importCreateUrl,
            statisticsDashboardUrl: $statisticsUrl,
            benchmarkingUrl: $benchmarkingUrl,
            notificationsSettingsUrl: $notificationsSettingsUrl,
            platformAllocationCount: $platformData['allocationCount'] ?? null,
            platformAllocationMomPercent: $platformData['allocationMomPercent'] ?? null,
            platformActiveHospitals: $platformData['activeHospitals'] ?? null,
            platformImportsLastMonth: $platformData['importsLastMonth'] ?? null,
        );
    }

    /**
     * @param list<\App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric> $metrics
     */
    private function findMetric(array $metrics, BenchmarkMetricKey $key): ?\App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric
    {
        foreach ($metrics as $metric) {
            if ($metric->key === $key) {
                return $metric;
            }
        }

        return null;
    }

    private function daysSinceImport(?Import $import, \DateTimeImmutable $referenceDate): ?int
    {
        $createdAt = $import?->getCreatedAt();
        if (!$createdAt instanceof \DateTimeImmutable) {
            return null;
        }

        return (int) $createdAt->diff($referenceDate)->days;
    }

    private function formatLastImportLabel(?Import $import, string $locale): string
    {
        $createdAt = $import?->getCreatedAt();
        if (!$createdAt instanceof \DateTimeImmutable) {
            return $this->trans('monthly_reminder.kpi.last_import.none', [], $locale);
        }

        return $this->trans('monthly_reminder.kpi.last_import.date', [
            'date' => $this->formatMonthYear((int) $createdAt->format('Y'), (int) $createdAt->format('n'), $locale),
        ], $locale);
    }

    private function formatMonthYear(int $year, int $month, string $locale): string
    {
        $date = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $formatted = \IntlDateFormatter::formatObject($date, 'LLLL yyyy', $locale);

        return false !== $formatted ? $formatted : sprintf('%04d-%02d', $year, $month);
    }

    private function formatChartMonthLabel(string $monthKey, string $locale): string
    {
        [$year, $month] = explode('-', $monthKey);
        $date = new \DateTimeImmutable(sprintf('%04d-%02d-01', (int) $year, (int) $month));
        $formatted = \IntlDateFormatter::formatObject($date, 'MMM', $locale);

        return false !== $formatted ? $formatted : $monthKey;
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    private function trans(string $id, array $parameters, string $locale): string
    {
        return $this->translator->trans($id, $parameters, 'engagement', $locale);
    }

    private function formatSignedPercent(float $value): string
    {
        return ($value >= 0 ? '+' : '').number_format($value, 1, '.', '').'%';
    }

    private function rejectionRateDelta(
        int $hospitalId,
        \DateTimeImmutable $reportingStart,
        \DateTimeImmutable $reportingEnd,
        \DateTimeImmutable $previousStart,
    ): ?float {
        $current = $this->kpiDailyRepository->rejectionRateForHospitalInRange($hospitalId, $reportingStart, $reportingEnd);
        $previous = $this->kpiDailyRepository->rejectionRateForHospitalInRange(
            $hospitalId,
            $previousStart,
            $reportingStart,
        );
        if (null === $current || null === $previous) {
            return null;
        }

        return round($current - $previous, 1);
    }

    private function urgencyBenchmarkNote(
        \App\Statistics\Benchmarking\Application\DTO\BenchmarkReport $report,
        string $baselinePeriodLabel,
        string $locale,
    ): ?string {
        foreach ($report->urgency->buckets as $bucket) {
            if ('1' !== $bucket->key && 'urgency_1' !== $bucket->key) {
                continue;
            }
            if (abs($bucket->primaryShare - $bucket->comparisonShare) < 1.0) {
                return null;
            }

            return $this->trans('monthly_reminder.urgency.benchmark', [
                'urgency' => $this->trans(AllocationUrgency::EMERGENCY->label(), [], $locale),
                'percent' => number_format($bucket->primaryShare, 1, '.', ''),
                'delta' => $this->formatSignedPercent($bucket->primaryShare - $bucket->comparisonShare),
                'baseline' => $baselinePeriodLabel,
            ], $locale);
        }

        return null;
    }

    /**
     * @param array<string, string> $submissionMonths
     * @param list<string>          $monthKeys
     */
    private function longestGapLabel(array $submissionMonths, array $monthKeys, string $locale): ?string
    {
        $longest = 0;
        $gapStart = null;
        $gapEnd = null;
        $current = 0;
        $currentStart = null;

        foreach ($monthKeys as $key) {
            if ('success' === ($submissionMonths[$key] ?? 'missing')) {
                if ($current > $longest) {
                    $longest = $current;
                    $gapStart = $currentStart;
                    $gapEnd = $key;
                }
                $current = 0;
                $currentStart = null;

                continue;
            }
            if (0 === $current) {
                $currentStart = $key;
            }
            ++$current;
        }

        if ($current > $longest) {
            $longest = $current;
            $gapStart = $currentStart;
            $lastMonthKey = [] !== $monthKeys ? $monthKeys[array_key_last($monthKeys)] : null;
            $gapEnd = $lastMonthKey;
        }

        if ($longest < 2 || null === $gapStart || null === $gapEnd) {
            return null;
        }

        return $this->trans('monthly_reminder.submission.gap', [
            'months' => $longest,
            'from' => $this->formatMonthKey($gapStart, $locale),
            'to' => $this->formatMonthKey($gapEnd, $locale),
        ], $locale);
    }

    private function formatMonthKey(string $key, string $locale): string
    {
        [$year, $month] = explode('-', $key);

        return $this->formatMonthYear((int) $year, (int) $month, $locale);
    }

    /**
     * @return list<HospitalInsight>
     */
    private function fallbackInsights(string $benchmarkingUrl, string $locale): array
    {
        return [
            new HospitalInsight(
                $this->trans('monthly_reminder.fallback.insight.upload.title', [], $locale),
                $this->trans('monthly_reminder.fallback.insight.upload.body', [], $locale),
                HospitalInsightTrend::Neutral,
            ),
            new HospitalInsight(
                $this->trans('monthly_reminder.fallback.insight.platform.title', [], $locale),
                $this->trans('monthly_reminder.fallback.insight.platform.body', [], $locale),
                HospitalInsightTrend::Neutral,
                $benchmarkingUrl,
            ),
        ];
    }

    /**
     * @param array{
     *     referenceDate: \DateTimeImmutable,
     *     reportingYear: int,
     *     reportingMonth: int,
     *     reportingMonthStart: \DateTimeImmutable,
     *     reportingMonthEnd: \DateTimeImmutable,
     *     uploadYear: int,
     *     uploadMonth: int,
     *     chartStart: \DateTimeImmutable,
     *     chartMonthKeys: list<string>,
     *     chartLabels: list<string>,
     * } $period
     *
     * @return array{allocationCount: int, allocationMomPercent: ?float, activeHospitals: int, importsLastMonth: int}
     */
    private function platformFallbackData(array $period, string $reportingKey, string $previousKey): array
    {
        $platformRows = $this->timeSeriesQuery->countByMonthInPeriod($period['chartStart'], null, null);
        $buckets = $this->chartBucketMapper->monthRowsToBucketCounts($platformRows);
        $current = $buckets[$reportingKey] ?? 0;
        $previous = $buckets[$previousKey] ?? 0;

        $globalImports = $this->importRepository->countImportsByMonthInRange(
            $period['chartStart'],
            null,
        );
        $importBuckets = $this->chartBucketMapper->monthRowsToBucketCounts($globalImports);

        return [
            'allocationCount' => $current,
            'allocationMomPercent' => $this->chartBuilder->percentChange($current, $previous),
            'activeHospitals' => $this->kpiDailyRepository->countActiveHospitalsLast30Days(),
            'importsLastMonth' => $importBuckets[$reportingKey] ?? 0,
        ];
    }

    /**
     * @param list<int> $values
     */
    private function averageMonthlyChange(array $values): float
    {
        $recent = \array_slice($values, -6);
        if (\count($recent) < 2) {
            return 0.0;
        }

        $first = (float) $recent[0];
        $last = (float) $recent[\count($recent) - 1];
        if ($first <= 0.0) {
            return 0.0;
        }

        return (($last - $first) / $first * 100.0) / (float) max(1, \count($recent) - 1);
    }
}
