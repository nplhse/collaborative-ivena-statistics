<?php

declare(strict_types=1);

namespace App\Statistics\Application\Analysis;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\Pivot\HospitalPivotDimension;
use App\Statistics\Application\Pivot\HospitalPivotMeasure;
use App\Statistics\Application\Pivot\HospitalPivotSelection;
use App\Statistics\Application\Pivot\PivotTableBuilder;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Infrastructure\Query\HospitalPivotQuery;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class HospitalPivotAnalysisDefinition implements AnalysisDefinitionInterface
{
    public function __construct(
        private HospitalPivotQuery $hospitalPivotQuery,
        private HospitalRepository $hospitalRepository,
        private PivotTableBuilder $pivotTableBuilder,
        private TranslatorInterface $translator,
    ) {
    }

    public function key(): string
    {
        return 'hospital_pivot';
    }

    public function labelTranslationKey(): string
    {
        return 'stats.analysis.hospital_pivot.label';
    }

    public function supports(StatisticsContext $context): bool
    {
        unset($context);

        return true;
    }

    public function build(StatisticsContext $context, string $view, string $chartType, StatisticsAnalysisDimension $dimension, StatisticsChartMeasure $chartMeasure = StatisticsChartMeasure::Absolute): StatisticWidget
    {
        unset($view, $chartType, $dimension, $chartMeasure);

        $selection = HospitalPivotSelection::fromQuery($context->pivotRows, $context->pivotCols, $context->pivotMeasure);
        $bounds = StatisticsPeriodResolver::resolve($context->filter);

        $cells = $this->hospitalPivotQuery->fetchCells(
            $bounds->from,
            $bounds->toExclusive,
            $this->hospitalIdsOrNull($context),
            $selection->rows,
            $selection->cols,
            $selection->measure,
        );

        $rowKeys = $this->orderedKeys($cells, 'row_key');
        $colKeys = $this->orderedKeys($cells, 'col_key');
        $pivot = $this->pivotTableBuilder->build($rowKeys, $colKeys, $cells, $this->labels($rowKeys), $this->labels($colKeys));

        $format = match ($selection->measure) {
            HospitalPivotMeasure::AvgBeds,
            HospitalPivotMeasure::AvgAllocations => static fn (float $v): string => sprintf('%.1f', $v),
            HospitalPivotMeasure::RowPercent => static fn (float $v): string => sprintf('%.1f%%', $v),
            default => static fn (float $v): string => (string) (int) round($v),
        };

        $matrix = [];
        $rowTotals = $pivot->rowTotals;
        foreach ($pivot->matrix as $rowIndex => $row) {
            $formatted = [];
            foreach ($row as $value) {
                if (HospitalPivotMeasure::RowPercent === $selection->measure) {
                    $denom = $rowTotals[$rowIndex] ?? 0.0;
                    $pct = $denom > 0 ? round(($value / $denom) * 100, 1) : 0.0;
                    $formatted[] = sprintf('%.1f%%', $pct);
                    continue;
                }
                $formatted[] = $format($value);
            }
            $matrix[] = $formatted;
        }

        $rowTotalsOut = array_map($format, $pivot->rowTotals);
        $colTotalsOut = array_map($format, $pivot->columnTotals);
        $grandTotalOut = $format($pivot->grandTotal);

        if (HospitalPivotMeasure::RowPercent === $selection->measure) {
            $rowTotalsOut = array_fill(0, \count($pivot->rowTotals), '100.0%');
            $colTotalsOut = array_fill(0, \count($pivot->columnTotals), '100.0%');
            $grandTotalOut = '100.0%';
        }

        $payload = [
            'rowDimensionLabel' => $this->translator->trans($this->dimensionLabel($selection->rows)),
            'columnDimensionLabel' => $this->translator->trans($this->dimensionLabel($selection->cols)),
            'rowLabels' => $pivot->rowLabels,
            'columnLabels' => $pivot->columnLabels,
            'matrix' => $matrix,
            'showTotals' => true,
            'row_totals' => $rowTotalsOut,
            'column_totals' => $colTotalsOut,
            'grand_total' => $grandTotalOut,
            'rowTotalHeaderLabel' => $this->translator->trans('stats.analysis.pivot.row_total'),
            'columnTotalFooterLabel' => $this->translator->trans('stats.analysis.pivot.column_total'),
            'grandTotalFooterLabel' => $this->translator->trans('stats.analysis.pivot.grand_total'),
        ];

        return new StatisticWidget(StatisticWidgetType::PivotTable, 'hospital_pivot_table', $payload);
    }

    /**
     * @param list<array{row_key: string, col_key: string, value: float}> $cells
     *
     * @return list<string>
     */
    private function orderedKeys(array $cells, string $key): array
    {
        $unique = [];
        foreach ($cells as $cell) {
            $unique[(string) $cell[$key]] = true;
        }

        $keys = array_keys($unique);
        natcasesort($keys);

        return array_values($keys);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function labels(array $keys): array
    {
        $map = [];
        foreach ($keys as $key) {
            $map[$key] = '' !== trim($key) ? $key : $this->translator->trans('stats.analysis.pivot.unknown');
        }

        return $map;
    }

    private function dimensionLabel(HospitalPivotDimension $dimension): string
    {
        return match ($dimension) {
            HospitalPivotDimension::State => 'stats.analysis.hospital_pivot.axis.state',
            HospitalPivotDimension::DispatchArea => 'stats.analysis.hospital_pivot.axis.dispatch_area',
            HospitalPivotDimension::Location => 'stats.analysis.hospital_pivot.axis.location',
            HospitalPivotDimension::Tier => 'stats.analysis.hospital_pivot.axis.tier',
            HospitalPivotDimension::Size => 'stats.analysis.hospital_pivot.axis.size',
        };
    }

    /**
     * @return list<int>|null
     */
    private function hospitalIdsOrNull(StatisticsContext $context): ?array
    {
        $filter = $context->filter;
        if ('public' === $filter->scope->value) {
            return null;
        }
        if (null !== $filter->hospitalId) {
            return [$filter->hospitalId];
        }

        if (null === $context->user) {
            return null;
        }

        /** @var list<int|string> $rawIds */
        $rawIds = $this->hospitalRepository
            ->getQueryBuilderForAccessibleHospitals($context->user)
            ->select('h.id')
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $rawIds) {
            return null;
        }

        return array_map(static fn (int|string $id): int => (int) $id, $rawIds);
    }
}
