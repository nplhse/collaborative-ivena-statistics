<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableRow;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableViewModel;
use App\Statistics\AnalysisExplorer\Application\DTO\MultiSeriesPivot;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerResultsTablePresenter
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function create(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): ExplorerResultsTableViewModel
    {
        if ($result->hasSeries()) {
            return $this->createPivotTable($viewConfig, $result);
        }

        $rows = [];
        foreach ($result->rows as $row) {
            $rows[] = new ExplorerResultsTableRow(
                bucketLabel: $row->bucketLabel,
                value: $row->value,
            );
        }

        return new ExplorerResultsTableViewModel(
            primaryDimensionLabel: $this->dimensionLabel($viewConfig->dimensionKey, $viewConfig->timeGrain),
            metricLabel: $this->metricLabel($viewConfig->metricKey),
            rows: $rows,
            total: $result->total,
        );
    }

    private function createPivotTable(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): ExplorerResultsTableViewModel
    {
        $pivot = MultiSeriesPivot::fromResult($result);

        $rows = [];
        $seriesTotals = [];

        foreach ($pivot->bucketOrder as $bucket) {
            $seriesValues = [];
            $rowTotal = 0;

            foreach ($pivot->seriesLabels as $seriesKey => $seriesLabel) {
                $value = $pivot->valuesByBucket[$bucket][$seriesKey] ?? 0;
                $seriesValues[$seriesLabel] = $value;
                $rowTotal += $value;
                $seriesTotals[$seriesLabel] = ($seriesTotals[$seriesLabel] ?? 0) + $value;
            }

            $rows[] = new ExplorerResultsTableRow(
                bucketLabel: $pivot->bucketLabels[$bucket],
                value: $rowTotal,
                seriesValues: $seriesValues,
                rowTotal: $rowTotal,
            );
        }

        $orderedSeriesLabels = array_values($pivot->seriesLabels);

        return new ExplorerResultsTableViewModel(
            primaryDimensionLabel: $this->temporalDimensionLabel($viewConfig->timeGrain),
            metricLabel: $this->metricLabel($viewConfig->metricKey),
            rows: $rows,
            total: $result->total,
            hasSeries: true,
            seriesLabels: $orderedSeriesLabels,
            seriesTotals: $seriesTotals,
        );
    }

    private function dimensionLabel(AnalysisDimensionKey $dimensionKey, ?AnalysisDimensionGrain $timeGrain): string
    {
        return match ($dimensionKey) {
            AnalysisDimensionKey::Time => $this->temporalDimensionLabel($timeGrain),
            AnalysisDimensionKey::Gender => $this->translator->trans('stats.analysis_explorer.dimension.gender'),
            AnalysisDimensionKey::Urgency => $this->translator->trans('stats.analysis_explorer.dimension.urgency'),
        };
    }

    private function temporalDimensionLabel(?AnalysisDimensionGrain $timeGrain): string
    {
        return match ($timeGrain) {
            AnalysisDimensionGrain::Year => $this->translator->trans('stats.analysis_explorer.dimension.year'),
            AnalysisDimensionGrain::Total => $this->translator->trans('stats.analysis_explorer.grain.total'),
            default => $this->translator->trans('stats.analysis_explorer.dimension.month'),
        };
    }

    private function metricLabel(AnalysisMetricKey $metricKey): string
    {
        return match ($metricKey) {
            AnalysisMetricKey::AllocationCount => $this->translator->trans('stats.analysis_explorer.metric.allocation_count'),
        };
    }
}
