<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableRow;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableViewModel;
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
        /** @var array<string, string> $bucketLabels */
        $bucketLabels = [];
        /** @var array<string, array<string, int>> $valuesByBucket */
        $valuesByBucket = [];
        /** @var array<string, string> $seriesLabels */
        $seriesLabels = [];
        /** @var array<string, int> $seriesTotals */
        $seriesTotals = [];

        foreach ($result->rows as $row) {
            $bucketLabels[$row->bucket] = $row->bucketLabel;
            $seriesKey = $row->seriesKey ?? '';
            $seriesLabels[$seriesKey] = $row->seriesLabel ?? $seriesKey;
            $valuesByBucket[$row->bucket][$seriesKey] = $row->value;
            $seriesTotals[$seriesKey] = ($seriesTotals[$seriesKey] ?? 0) + $row->value;
        }

        $rows = [];
        foreach ($bucketLabels as $bucket => $bucketLabel) {
            $seriesValues = [];
            $rowTotal = 0;
            foreach ($seriesLabels as $seriesKey => $seriesLabel) {
                $value = $valuesByBucket[$bucket][$seriesKey] ?? 0;
                $seriesValues[$seriesLabel] = $value;
                $rowTotal += $value;
            }

            $rows[] = new ExplorerResultsTableRow(
                bucketLabel: $bucketLabel,
                value: $rowTotal,
                seriesValues: $seriesValues,
                rowTotal: $rowTotal,
            );
        }

        $orderedSeriesLabels = array_values($seriesLabels);
        $orderedSeriesTotals = [];
        foreach ($seriesLabels as $seriesKey => $seriesLabel) {
            $orderedSeriesTotals[$seriesLabel] = $seriesTotals[$seriesKey] ?? 0;
        }

        return new ExplorerResultsTableViewModel(
            primaryDimensionLabel: $this->temporalDimensionLabel($viewConfig->timeGrain),
            metricLabel: $this->metricLabel($viewConfig->metricKey),
            rows: $rows,
            total: $result->total,
            hasSeries: true,
            seriesLabels: $orderedSeriesLabels,
            seriesTotals: $orderedSeriesTotals,
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
