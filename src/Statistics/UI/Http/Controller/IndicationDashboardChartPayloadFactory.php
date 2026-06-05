<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardResult;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationHeatmapData;

/**
 * @phpstan-type ChartPayload array<string, mixed>
 */
final readonly class IndicationDashboardChartPayloadFactory
{
    /**
     * @return ChartPayload
     */
    public function create(IndicationDashboardResult $result): array
    {
        return [
            'timeSeries' => [
                'labels' => $result->timeSeries->labels,
                'values' => $result->timeSeries->values,
            ],
            'heatmapDayTime' => $this->heatmapPayload($result->dayTimeHeatmap),
            'heatmapShift' => $this->heatmapPayload($result->shiftHeatmap),
        ];
    }

    /**
     * @return array{rowLabels:list<string>,columnLabels:list<string>,matrix:list<list<int>>,max:int}
     */
    private function heatmapPayload(IndicationHeatmapData $heatmap): array
    {
        return [
            'rowLabels' => $heatmap->rowLabels,
            'columnLabels' => $heatmap->columnLabels,
            'matrix' => $heatmap->matrix,
            'max' => $heatmap->maxCount,
        ];
    }
}
