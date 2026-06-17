<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\IndicationCompare\DTO\IndicationCompareReport;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistribution;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkHeatmapData;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class IndicationCompareChartPayloadFactory
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(IndicationCompareReport $report): array
    {
        return [
            'ageGroups' => $this->distributionPayload($report->ageGroupDistribution),
            'transportTimes' => $this->distributionPayload($report->transportTimeDistribution),
            'heatmapDayTime' => $this->heatmapPayload($report->dayTimeHeatmap),
            'heatmapShift' => $this->heatmapPayload($report->shiftHeatmap),
        ];
    }

    /**
     * @return array{
     *     rowLabels: list<string>,
     *     columnLabels: list<string>,
     *     matrix: list<list<float>>,
     *     primaryShares: list<list<float>>,
     *     comparisonShares: list<list<float>>,
     *     maxAbsDelta: float
     * }
     */
    private function heatmapPayload(BenchmarkHeatmapData $heatmap): array
    {
        return [
            'rowLabels' => $heatmap->rowLabels,
            'columnLabels' => $heatmap->columnLabels,
            'matrix' => $heatmap->deltaMatrix,
            'primaryShares' => $heatmap->primaryShareMatrix,
            'comparisonShares' => $heatmap->comparisonShareMatrix,
            'maxAbsDelta' => $heatmap->maxAbsDelta,
        ];
    }

    /**
     * @return array{labels: list<string>, primary: list<float>, comparison: list<float>}
     */
    private function distributionPayload(BenchmarkDistribution $distribution): array
    {
        $labels = [];
        $primary = [];
        $comparison = [];

        foreach ($distribution->buckets as $bucket) {
            $labels[] = $this->translateDistributionLabel($bucket->label);
            $primary[] = round($bucket->primaryShare, 1);
            $comparison[] = round($bucket->comparisonShare, 1);
        }

        return [
            'labels' => $labels,
            'primary' => $primary,
            'comparison' => $comparison,
        ];
    }

    private function translateDistributionLabel(string $label): string
    {
        if (str_starts_with($label, 'stats.') || str_starts_with($label, 'statistics.')) {
            return $this->translator->trans($label);
        }

        return $label;
    }
}
