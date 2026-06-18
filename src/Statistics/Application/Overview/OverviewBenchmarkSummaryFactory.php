<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\Overview\Dto\OverviewBenchmarkDeviation;
use App\Statistics\Application\Overview\Dto\OverviewBenchmarkScorecardItem;
use App\Statistics\Application\Overview\Dto\OverviewKpiCard;
use App\Statistics\Benchmarking\Application\BenchmarkMetricBuilder;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricFormat;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkReport;
use App\Statistics\Benchmarking\UI\Http\Controller\BenchmarkIndicationMixViewModelFactory;
use Symfony\Component\HttpFoundation\Request;

final readonly class OverviewBenchmarkSummaryFactory
{
    private const float OVER_RATIO = BenchmarkMetricBuilder::INDICATION_OVER_RATIO;

    private const float UNDER_RATIO = BenchmarkMetricBuilder::INDICATION_UNDER_RATIO;

    /** @var list<BenchmarkMetricKey> */
    private const array SCORECARD_KEYS = [
        BenchmarkMetricKey::WithPhysician,
        BenchmarkMetricKey::MedianTransport,
        BenchmarkMetricKey::Resus,
        BenchmarkMetricKey::MedianAge,
    ];

    public function __construct(
        private BenchmarkIndicationMixViewModelFactory $indicationMixViewModelFactory,
    ) {
    }

    /**
     * @return list<OverviewBenchmarkScorecardItem>
     */
    public function buildScorecard(BenchmarkReport $report): array
    {
        if ($report->hasInsufficientData) {
            return [];
        }

        $items = [];
        foreach (self::SCORECARD_KEYS as $key) {
            $metric = $this->findMetric($report->kpiMetrics, $key);
            if (!$metric instanceof BenchmarkMetric) {
                continue;
            }

            $status = $this->benchmarkStatus($metric->ratio, $report->suppressRatios);
            $items[] = new OverviewBenchmarkScorecardItem(
                $key->value,
                'stats.benchmark.metric.'.$key->value,
                $this->formatMetricValue($metric),
                $status,
                'stats.overview.executive.benchmark.status.'.$status,
            );
        }

        return $items;
    }

    /**
     * @return list<OverviewKpiCard>
     */
    public function buildScorecardKpiCards(BenchmarkReport $report): array
    {
        $cards = [];
        foreach ($this->buildScorecard($report) as $item) {
            $cards[] = new OverviewKpiCard(
                'benchmark_'.$item->key,
                $item->labelTranslationKey,
                $item->displayValue,
                null,
                null,
                $item->statusLabelTranslationKey,
            );
        }

        return $cards;
    }

    /**
     * @return array{positive: list<OverviewBenchmarkDeviation>, negative: list<OverviewBenchmarkDeviation>}
     */
    public function buildDeviations(Request $request, BenchmarkReport $report): array
    {
        if ($report->hasInsufficientData || $report->suppressRatios) {
            return ['positive' => [], 'negative' => []];
        }

        $mixViewModel = $this->indicationMixViewModelFactory->create($request, $report->indicationMix);
        $positive = [];
        foreach ($mixViewModel->overRepresented as $row) {
            $positive[] = new OverviewBenchmarkDeviation(
                $row->bucket->label,
                $row->bucket->ratio,
                'above',
                $row->insightsUrl,
            );
        }

        $negative = [];
        foreach ($mixViewModel->underRepresented as $row) {
            $negative[] = new OverviewBenchmarkDeviation(
                $row->bucket->label,
                $row->bucket->ratio,
                'below',
                $row->insightsUrl,
            );
        }

        usort($positive, static fn (OverviewBenchmarkDeviation $a, OverviewBenchmarkDeviation $b): int => $b->ratio <=> $a->ratio);
        usort($negative, static fn (OverviewBenchmarkDeviation $a, OverviewBenchmarkDeviation $b): int => $a->ratio <=> $b->ratio);

        return [
            'positive' => \array_slice($positive, 0, 3),
            'negative' => \array_slice($negative, 0, 3),
        ];
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

    private function benchmarkStatus(float $ratio, bool $suppressRatios): string
    {
        if ($suppressRatios) {
            return 'within';
        }

        if ($ratio >= self::OVER_RATIO) {
            return 'above';
        }

        if ($ratio <= self::UNDER_RATIO) {
            return 'below';
        }

        return 'within';
    }

    private function formatMetricValue(BenchmarkMetric $metric): string
    {
        return match ($metric->format) {
            BenchmarkMetricFormat::Percent => number_format($metric->primaryValue, 1, ',', '.').'%',
            BenchmarkMetricFormat::Count => number_format($metric->primaryValue, 0, ',', '.'),
            BenchmarkMetricFormat::Minutes => number_format($metric->primaryValue, 1, ',', '.').' min',
            BenchmarkMetricFormat::Decimal, BenchmarkMetricFormat::Years => number_format($metric->primaryValue, 1, ',', '.'),
        };
    }
}
