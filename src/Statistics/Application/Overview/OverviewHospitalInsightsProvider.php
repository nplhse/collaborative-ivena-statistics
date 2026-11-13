<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\Insights\HospitalInsight;
use App\Statistics\Application\Insights\HospitalInsightSelector;
use App\Statistics\Application\Insights\HospitalInsightTrend;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkReport;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Insights of the overview card: volume, physician and resus rate and indication mix, each against the previous
 * period of the same length (#1071). Without a comparison period (all time), or with fewer than 100 allocations on
 * either side, there are no insights.
 */
final readonly class OverviewHospitalInsightsProvider
{
    private const int MAX_INSIGHTS = 3;

    /**
     * Minimum allocations in the period and in the comparison period. The general benchmark asks for 500 comparison
     * cases, which suits a large comparison group but not the previous period of one scope (#1071).
     */
    private const int MIN_CASES = 100;

    public function __construct(
        private HospitalInsightSelector $insightSelector,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<HospitalInsight>
     */
    public function build(
        BenchmarkReport $benchmarkReport,
        ?OverviewComparisonPeriod $comparison,
        string $benchmarkingUrl,
        string $reportingPeriodLabel,
    ): array {
        if (!$comparison instanceof OverviewComparisonPeriod) {
            return [];
        }

        $total = $this->findMetric($benchmarkReport->kpiMetrics, BenchmarkMetricKey::Total);
        if (!$total instanceof BenchmarkMetric
            || $total->primaryValue < self::MIN_CASES
            || $total->comparisonValue < self::MIN_CASES
        ) {
            return [];
        }

        $comparisonLabel = $this->translator->trans($comparison->labelKey(), [], 'statistics');

        $insights = [];
        $volumeInsight = $this->volumeInsight($total, $comparisonLabel);
        if ($volumeInsight instanceof HospitalInsight) {
            $insights[] = $volumeInsight;
        }

        $insights = [
            ...$insights,
            ...$this->insightSelector->select(
                null,
                null,
                array_values(array_filter([
                    $this->findMetric($benchmarkReport->kpiMetrics, BenchmarkMetricKey::WithPhysician),
                    $this->findMetric($benchmarkReport->kpiMetrics, BenchmarkMetricKey::Resus),
                ])),
                $benchmarkReport->indicationMix,
                null,
                $benchmarkingUrl,
                $comparisonLabel,
                $reportingPeriodLabel,
            ),
        ];

        return \array_slice($insights, 0, self::MAX_INSIGHTS);
    }

    private function volumeInsight(BenchmarkMetric $total, string $comparisonLabel): ?HospitalInsight
    {
        $percent = OverviewPeriodComparisonService::relativePercentChange($total->primaryValue, $total->comparisonValue);
        if (null === $percent || abs($percent) < HospitalInsightSelector::VOLUME_CHANGE_THRESHOLD) {
            return null;
        }

        return new HospitalInsight(
            $this->translator->trans('stats.overview.insight.volume.title', [], 'statistics'),
            $this->translator->trans('stats.overview.insight.volume.body', [
                'percent' => ($percent >= 0 ? '+' : '').number_format($percent, 1, '.', '').'%',
                'comparison' => $comparisonLabel,
            ], 'statistics'),
            $percent >= 0 ? HospitalInsightTrend::Up : HospitalInsightTrend::Down,
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
