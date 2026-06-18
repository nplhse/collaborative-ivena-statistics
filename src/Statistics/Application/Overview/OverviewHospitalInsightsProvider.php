<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\Insights\HospitalInsight;
use App\Statistics\Application\Insights\HospitalInsightSelector;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkReport;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class OverviewHospitalInsightsProvider
{
    private const int MIN_CASES = 20;

    public function __construct(
        private HospitalInsightSelector $insightSelector,
        private OverviewPeriodComparisonService $periodComparison,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<HospitalInsight>
     */
    public function build(
        StatisticsContext $context,
        BenchmarkReport $benchmarkReport,
        int $currentTotal,
        ?int $previousTotal,
        string $benchmarkingUrl,
        string $reportingPeriodLabel,
    ): array {
        if ($benchmarkReport->hasInsufficientData || $currentTotal < self::MIN_CASES) {
            return [];
        }

        $allocationMomPercent = null;
        if ($this->periodComparison->supportsPop($context->filter)) {
            $allocationMomPercent = OverviewPeriodComparisonService::relativePercentChange(
                $currentTotal,
                $previousTotal,
            );
        }

        return $this->insightSelector->select(
            $allocationMomPercent,
            null,
            array_values(array_filter([
                $this->findMetric($benchmarkReport->kpiMetrics, BenchmarkMetricKey::WithPhysician),
                $this->findMetric($benchmarkReport->kpiMetrics, BenchmarkMetricKey::Resus),
            ])),
            $benchmarkReport->indicationMix,
            null,
            $benchmarkingUrl,
            $this->translator->trans('monthly_reminder.baseline.period_label'),
            $reportingPeriodLabel,
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
}
