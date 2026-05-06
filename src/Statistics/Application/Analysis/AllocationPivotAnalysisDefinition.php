<?php

declare(strict_types=1);

namespace App\Statistics\Application\Analysis;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\Pivot\AllocationPivotDimension;
use App\Statistics\Application\Pivot\AllocationPivotMeasure;
use App\Statistics\Application\Pivot\AllocationPivotSelection;
use App\Statistics\Application\Pivot\PivotTableBuilder;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Infrastructure\Query\AllocationPivotQuery;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AllocationPivotAnalysisDefinition implements AnalysisDefinitionInterface
{
    public function __construct(
        private AllocationPivotQuery $allocationPivotQuery,
        private StatisticsScopeResolver $scopeResolver,
        private PivotTableBuilder $pivotTableBuilder,
        private TranslatorInterface $translator,
    ) {
    }

    public function key(): string
    {
        return 'allocation_pivot';
    }

    public function labelTranslationKey(): string
    {
        return 'stats.analysis.allocation_pivot.label';
    }

    public function supports(StatisticsContext $context): bool
    {
        unset($context);

        return true;
    }

    public function build(StatisticsContext $context, string $view, string $chartType, StatisticsAnalysisDimension $dimension, StatisticsChartMeasure $chartMeasure = StatisticsChartMeasure::Absolute): StatisticWidget
    {
        unset($view, $chartType, $dimension, $chartMeasure);

        $selection = AllocationPivotSelection::fromQuery($context->pivotRows, $context->pivotCols, $context->pivotMeasure);
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $cells = $this->allocationPivotQuery->fetchCells(
            $bounds->from,
            $bounds->toExclusive,
            $this->scopeResolver->hospitalIdsOrNull($context),
            $selection->rows,
            $selection->cols,
        );

        $rowKeys = $this->orderedAllocationKeys($cells, $selection->rows, 'row_key');
        $colKeys = $this->orderedAllocationKeys($cells, $selection->cols, 'col_key');
        $rowLabelOverrides = AllocationPivotDimension::Department === $selection->rows
            ? $this->labelsFromCells($cells, 'row_key', 'row_label')
            : [];
        $colLabelOverrides = AllocationPivotDimension::Department === $selection->cols
            ? $this->labelsFromCells($cells, 'col_key', 'col_label')
            : [];
        $pivot = $this->pivotTableBuilder->build(
            $rowKeys,
            $colKeys,
            $cells,
            array_replace($this->labelMap($rowKeys, $selection->rows), $rowLabelOverrides),
            array_replace($this->labelMap($colKeys, $selection->cols), $colLabelOverrides),
        );

        $matrix = [];
        $rowTotals = $pivot->rowTotals;
        foreach ($pivot->matrix as $rowIndex => $rowValues) {
            $formattedRow = [];
            foreach ($rowValues as $value) {
                if (AllocationPivotMeasure::RowPercent === $selection->measure) {
                    $denominator = $rowTotals[$rowIndex] ?? 0.0;
                    $pct = $denominator > 0 ? round(($value / $denominator) * 100, 1) : 0.0;
                    $formattedRow[] = sprintf('%.1f%%', $pct);
                    continue;
                }
                $formattedRow[] = (string) (int) round($value);
            }
            $matrix[] = $formattedRow;
        }

        $showTotals = true;
        $rowTotalsOut = array_map(static fn (float $v): string => (string) (int) round($v), $pivot->rowTotals);
        $colTotalsOut = array_map(static fn (float $v): string => (string) (int) round($v), $pivot->columnTotals);
        $grandTotalOut = (string) (int) round($pivot->grandTotal);

        if (AllocationPivotMeasure::RowPercent === $selection->measure) {
            $showTotals = true;
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
            'showTotals' => $showTotals,
            'row_totals' => $rowTotalsOut,
            'column_totals' => $colTotalsOut,
            'grand_total' => $grandTotalOut,
            'rowTotalHeaderLabel' => $this->translator->trans('stats.analysis.pivot.row_total'),
            'columnTotalFooterLabel' => $this->translator->trans('stats.analysis.pivot.column_total'),
            'grandTotalFooterLabel' => $this->translator->trans('stats.analysis.pivot.grand_total'),
        ];

        return new StatisticWidget(StatisticWidgetType::PivotTable, 'allocation_pivot_table', $payload);
    }

    /**
     * @param list<array{row_key: string, col_key: string, value: float}> $cells
     *
     * @return list<string>
     */
    private function orderedAllocationKeys(array $cells, AllocationPivotDimension $dimension, string $key): array
    {
        $seen = [];
        foreach ($cells as $cell) {
            $seen[(string) $cell[$key]] = true;
        }
        $keys = array_keys($seen);

        if ([] === $keys) {
            return match ($dimension) {
                AllocationPivotDimension::Gender => array_map(static fn (AllocationGender $gender): string => $gender->value, AllocationGender::cases()),
                AllocationPivotDimension::Urgency => array_map(static fn (AllocationUrgency $urgency): string => (string) $urgency->value, AllocationUrgency::cases()),
                default => [],
            };
        }

        if (AllocationPivotDimension::Urgency === $dimension) {
            usort($keys, static fn (string $a, string $b): int => (int) $a <=> (int) $b);
        } elseif (AllocationPivotDimension::AgeGroup === $dimension) {
            $order = array_flip(['unknown', '0_18', '19_29', '30_39', '40_49', '50_59', '60_69', '70_79', '80_89', '90_99', '100p']);
            usort($keys, static fn (string $a, string $b): int => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));
        } else {
            natcasesort($keys);
            $keys = array_values($keys);
        }

        return $keys;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function labelMap(array $keys, AllocationPivotDimension $dimension): array
    {
        $map = [];
        foreach ($keys as $key) {
            $map[$key] = match ($dimension) {
                AllocationPivotDimension::Gender => $this->translator->trans(
                    AllocationGender::tryFrom($key)?->label() ?? 'stats.analysis.pivot.unknown',
                ),
                AllocationPivotDimension::Urgency => $this->translator->trans(match ((int) $key) {
                    AllocationUrgency::EMERGENCY->value => 'stats.overview.hospital_summary.urgency_u1',
                    AllocationUrgency::INPATIENT->value => 'stats.overview.hospital_summary.urgency_u2',
                    AllocationUrgency::OUTPATIENT->value => 'stats.overview.hospital_summary.urgency_u3',
                    default => 'stats.analysis.pivot.unknown',
                }),
                AllocationPivotDimension::AgeGroup => $this->translator->trans('stats.analysis.pivot.age.'.$this->ageLabelKey($key)),
                AllocationPivotDimension::Department => $key,
            };
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $cells
     *
     * @return array<string, string>
     */
    private function labelsFromCells(array $cells, string $keyField, string $labelField): array
    {
        $map = [];
        foreach ($cells as $cell) {
            if (!isset($cell[$keyField], $cell[$labelField])) {
                continue;
            }
            $key = (string) $cell[$keyField];
            if ('' === $key) {
                continue;
            }
            if (!isset($map[$key])) {
                $map[$key] = (string) $cell[$labelField];
            }
        }

        return $map;
    }

    private function dimensionLabel(AllocationPivotDimension $dimension): string
    {
        return match ($dimension) {
            AllocationPivotDimension::Gender => 'stats.analysis.pivot.axis.cols.gender',
            AllocationPivotDimension::Urgency => 'stats.analysis.pivot.axis.rows.urgency',
            AllocationPivotDimension::AgeGroup => 'stats.analysis.pivot.axis.rows.age_group',
            AllocationPivotDimension::Department => 'stats.analysis.pivot.axis.rows.department',
        };
    }

    private function ageLabelKey(string $key): string
    {
        return match ($key) {
            '0_18' => '0_18',
            '19_29' => '19_29',
            '30_39' => '30_39',
            '40_49' => '40_49',
            '50_59' => '50_59',
            '60_69' => '60_69',
            '70_79' => '70_79',
            '80_89' => '80_89',
            '90_99' => '90_99',
            '100p' => '100_plus',
            default => 'unknown',
        };
    }

}
