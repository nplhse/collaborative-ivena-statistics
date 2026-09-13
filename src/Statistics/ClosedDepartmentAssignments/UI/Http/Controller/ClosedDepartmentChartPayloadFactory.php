<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\UI\Http\Controller;

use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentDistributionRow;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentHeatmapData;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentTimeSeries;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentTransportStats;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @phpstan-type ChartPayload array<string, mixed>
 */
final readonly class ClosedDepartmentChartPayloadFactory
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return ChartPayload
     */
    public function createSummary(ClosedDepartmentTimeSeries $timeSeries, ClosedDepartmentHeatmapData $heatmap): array
    {
        return [
            'timeSeries' => $this->timeSeriesPayload($timeSeries),
            'heatmap' => $this->heatmapPayload($heatmap),
        ];
    }

    /**
     * @return ChartPayload
     */
    public function createTransport(ClosedDepartmentTransportStats $transport): array
    {
        return [
            'transport' => $this->groupedTransportPayload($transport),
        ];
    }

    /**
     * @return array{labels: list<string>, counts: list<int>, shares: list<?float>}
     */
    private function timeSeriesPayload(ClosedDepartmentTimeSeries $series): array
    {
        return [
            'labels' => $series->labels,
            'counts' => $series->counts,
            'shares' => $series->shares,
        ];
    }

    /**
     * @return array{rowLabels: list<string>, columnLabels: list<string>, matrix: list<list<int>>}
     */
    private function heatmapPayload(ClosedDepartmentHeatmapData $heatmap): array
    {
        return [
            'rowLabels' => $heatmap->rowLabels,
            'columnLabels' => $heatmap->columnLabels,
            'matrix' => $heatmap->matrix,
        ];
    }

    /**
     * @return array{
     *     labels: list<string>,
     *     closedShares: list<float>,
     *     totalShares: list<float>,
     *     closedLabel: string,
     *     totalLabel: string
     * }
     */
    private function groupedTransportPayload(ClosedDepartmentTransportStats $transport): array
    {
        return [
            'labels' => array_map(
                fn (ClosedDepartmentDistributionRow $row): string => $this->translator->trans(
                    $row->labelTranslationKey,
                    [],
                    'statistics',
                ),
                $transport->closedBuckets,
            ),
            'closedShares' => array_map(
                static fn (ClosedDepartmentDistributionRow $row): float => $row->percent ?? 0.0,
                $transport->closedBuckets,
            ),
            'totalShares' => array_map(
                static fn (ClosedDepartmentDistributionRow $row): float => $row->percent ?? 0.0,
                $transport->totalBuckets,
            ),
            'closedLabel' => $this->translator->trans('stats.closed_department.compare.closed', [], 'statistics'),
            'totalLabel' => $this->translator->trans('stats.closed_department.compare.total', [], 'statistics'),
        ];
    }
}
