<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Http\Controller;

use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistribution;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkHeatmapData;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkReport;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class BenchmarkChartPayloadFactory
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(BenchmarkReport $report): array
    {
        return [
            'ageGroups' => $this->distributionPayload($report->ageGroups),
            'transportTimes' => $this->distributionPayload($report->transportTimes),
            'heatmapDayTime' => $this->heatmapPayload($report->dayTimeCaseDistribution),
            'heatmapShift' => $this->heatmapPayload($report->shiftCaseDistribution),
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
            return $this->translator->trans($label, [], 'statistics');
        }

        if (str_starts_with($label, 'field.') || str_starts_with($label, 'label.')) {
            return $this->translator->trans($label, [], 'messages');
        }

        return $label;
    }
}
