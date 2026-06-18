<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Benchmarking\Application\BenchmarkMetricBuilder;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricFormat;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkReport;

final readonly class OverviewExecutiveKpiFactory
{
    /** @var list<BenchmarkMetricKey> */
    private const array ORDERED_KEYS = [
        BenchmarkMetricKey::CasesPerDay,
        BenchmarkMetricKey::MedianAge,
        BenchmarkMetricKey::Age80Plus,
        BenchmarkMetricKey::NightDaytime,
        BenchmarkMetricKey::Weekend,
        BenchmarkMetricKey::MedianTransport,
    ];

    public function __construct(
        private BenchmarkMetricBuilder $metricBuilder,
        private OverviewPeriodComparisonService $periodComparison,
        private OverviewSelfBenchmarkFactory $selfBenchmarkFactory,
    ) {
    }

    /**
     * @return list<BenchmarkMetric>
     */
    public function build(StatisticsContext $context, BenchmarkReport $report): array
    {
        $metricsByKey = [];
        foreach ($report->kpiMetrics as $metric) {
            $metricsByKey[$metric->key->value] = $metric;
        }

        $metrics = [];
        foreach (self::ORDERED_KEYS as $key) {
            if (BenchmarkMetricKey::CasesPerDay === $key) {
                $metrics[] = $this->buildCasesPerDayMetric($context, $report);

                continue;
            }

            if (isset($metricsByKey[$key->value])) {
                $metrics[] = $metricsByKey[$key->value];
            }
        }

        return $metrics;
    }

    private function buildCasesPerDayMetric(StatisticsContext $context, BenchmarkReport $report): BenchmarkMetric
    {
        $totalMetric = $this->metricBuilder->metricByKey($report->kpiMetrics, BenchmarkMetricKey::Total);
        $primaryTotal = $totalMetric instanceof BenchmarkMetric ? $totalMetric->primaryValue : 0.0;
        $comparisonTotal = $totalMetric instanceof BenchmarkMetric ? $totalMetric->comparisonValue : 0.0;

        $primaryDays = $this->periodComparison->periodDayCount($context->filter);
        $baselineDays = $this->periodComparison->periodDayCount(
            $this->selfBenchmarkFactory->baselineFilterFor($context->filter),
        );

        $primaryValue = null !== $primaryDays && $primaryDays > 0
            ? round($primaryTotal / (float) $primaryDays, 1)
            : 0.0;
        $comparisonValue = null !== $baselineDays && $baselineDays > 0
            ? round($comparisonTotal / (float) $baselineDays, 1)
            : 0.0;
        $absoluteDelta = round($primaryValue - $comparisonValue, 1);

        return new BenchmarkMetric(
            BenchmarkMetricKey::CasesPerDay,
            $primaryValue,
            $comparisonValue,
            $absoluteDelta,
            $comparisonValue > 0.0 ? ($absoluteDelta / $comparisonValue) * 100.0 : 0.0,
            $comparisonValue > 0.0 ? $primaryValue / $comparisonValue : 0.0,
            BenchmarkMetricFormat::Decimal,
        );
    }
}
