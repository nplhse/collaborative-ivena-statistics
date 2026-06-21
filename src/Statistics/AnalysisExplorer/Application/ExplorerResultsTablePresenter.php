<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableMetricColumn;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableRow;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableViewModel;
use App\Statistics\AnalysisExplorer\Application\DTO\MultiSeriesPivot;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\GenericAnalysis\Application\MetricValueFormatter;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerResultsTablePresenter
{
    public function __construct(
        private TranslatorInterface $translator,
        private ExplorerMetricKeyMapper $metricKeyMapper,
        private MetricRegistry $metricRegistry,
        private MetricValueFormatter $metricValueFormatter,
    ) {
    }

    public function create(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): ExplorerResultsTableViewModel
    {
        if ($result->hasSeries()) {
            return $this->createPivotTable($viewConfig, $result);
        }

        $metricColumns = $this->metricColumns($result->metricKeys);
        $rows = [];
        foreach ($result->rows as $row) {
            $rows[] = new ExplorerResultsTableRow(
                bucketLabel: $row->bucketLabel,
                formattedMetricValues: $this->formatRowValues($result->metricKeys, $row->metricValues),
            );
        }

        return new ExplorerResultsTableViewModel(
            primaryDimensionLabel: $this->dimensionLabel($viewConfig->dimensionKey, $viewConfig->timeGrain),
            metricColumns: $metricColumns,
            rows: $rows,
            formattedTotals: $this->formatTotals($result),
        );
    }

    private function createPivotTable(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): ExplorerResultsTableViewModel
    {
        $pivot = MultiSeriesPivot::fromResult($result, $result->visualMetricKey);
        $metricColumns = $this->metricColumns([$result->visualMetricKey]);

        $rows = [];
        $seriesTotals = [];

        foreach ($pivot->bucketOrder as $bucket) {
            $seriesValues = [];
            $rowTotal = 0.0;

            foreach ($pivot->seriesLabels as $seriesKey => $seriesLabel) {
                $value = (float) ($pivot->valuesByBucket[$bucket][$seriesKey] ?? 0.0);
                $seriesValues[$seriesLabel] = $value;
                $rowTotal += $value;
                $seriesTotals[$seriesLabel] = ($seriesTotals[$seriesLabel] ?? 0.0) + $value;
            }

            $rows[] = new ExplorerResultsTableRow(
                bucketLabel: $pivot->bucketLabels[$bucket],
                formattedMetricValues: [
                    $result->visualMetricKey->value => $this->formatMetricValue($result->visualMetricKey, $rowTotal),
                ],
                seriesValues: $seriesValues,
                formattedRowTotal: $this->formatMetricValue($result->visualMetricKey, $rowTotal),
                rowTotal: $rowTotal,
            );
        }

        $formattedSeriesTotals = [];
        foreach ($pivot->seriesLabels as $seriesLabel) {
            $formattedSeriesTotals[$seriesLabel] = $this->formatMetricValue(
                $result->visualMetricKey,
                (float) ($seriesTotals[$seriesLabel] ?? 0),
            );
        }

        return new ExplorerResultsTableViewModel(
            primaryDimensionLabel: $this->temporalDimensionLabel($viewConfig->timeGrain),
            metricColumns: $metricColumns,
            rows: $rows,
            formattedTotals: $this->formatTotals($result),
            hasSeries: true,
            seriesLabels: array_values($pivot->seriesLabels),
            formattedSeriesTotals: $formattedSeriesTotals,
            formattedGrandTotal: $this->formatMetricValue($result->visualMetricKey, $result->primaryTotal()),
        );
    }

    /**
     * @param list<AnalysisMetricKey> $metricKeys
     *
     * @return list<ExplorerResultsTableMetricColumn>
     */
    private function metricColumns(array $metricKeys): array
    {
        $columns = [];
        foreach ($metricKeys as $metricKey) {
            $columns[] = new ExplorerResultsTableMetricColumn(
                key: $metricKey->value,
                label: $this->metricLabel($metricKey),
            );
        }

        return $columns;
    }

    /**
     * @param list<AnalysisMetricKey>       $metricKeys
     * @param array<string, int|float|null> $values
     *
     * @return array<string, string>
     */
    private function formatRowValues(array $metricKeys, array $values): array
    {
        $formatted = [];
        foreach ($metricKeys as $metricKey) {
            $formatted[$metricKey->value] = $this->formatMetricValue(
                $metricKey,
                $values[$metricKey->value] ?? null,
            );
        }

        return $formatted;
    }

    /**
     * @return array<string, string>
     */
    private function formatTotals(AnalysisRunResult $result): array
    {
        $formatted = [];
        foreach ($result->metricKeys as $metricKey) {
            $formatted[$metricKey->value] = $this->formatMetricValue(
                $metricKey,
                $result->totalFor($metricKey),
            );
        }

        return $formatted;
    }

    private function formatMetricValue(AnalysisMetricKey $metricKey, int|float|null $value): string
    {
        $registryKey = $this->metricKeyMapper->toRegistryKey($metricKey);

        return $this->metricValueFormatter->format(
            $this->metricRegistry->get($registryKey),
            $value,
        );
    }

    private function dimensionLabel(AnalysisDimensionKey $dimensionKey, ?AnalysisDimensionGrain $timeGrain): string
    {
        if ($dimensionKey->isTemporalPrimary()) {
            return $this->temporalDimensionLabel($timeGrain);
        }

        return $this->translator->trans('stats.analysis_explorer.dimension.'.$dimensionKey->value);
    }

    private function temporalDimensionLabel(?AnalysisDimensionGrain $timeGrain): string
    {
        return match ($timeGrain) {
            AnalysisDimensionGrain::Year => $this->translator->trans('stats.analysis_explorer.dimension.year'),
            AnalysisDimensionGrain::Quarter => $this->translator->trans('stats.analysis_explorer.dimension.quarter'),
            AnalysisDimensionGrain::Week => $this->translator->trans('stats.analysis_explorer.dimension.week'),
            AnalysisDimensionGrain::Total => $this->translator->trans('stats.analysis_explorer.grain.total'),
            default => $this->translator->trans('stats.analysis_explorer.dimension.month'),
        };
    }

    private function metricLabel(AnalysisMetricKey $metricKey): string
    {
        return $this->translator->trans('stats.analysis_explorer.metric.'.$metricKey->value);
    }
}
