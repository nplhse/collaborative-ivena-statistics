<?php

declare(strict_types=1);

namespace App\Statistics\Application\Analysis;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\DTO\PivotColAxis;
use App\Statistics\Application\DTO\PivotRowAxis;
use App\Statistics\Application\DTO\PivotTableAxes;
use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Infrastructure\Query\PivotAllocationAggregationQuery;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class PivotAnalysis implements AnalysisDefinitionInterface
{
    /** @var array<int, string> */
    private const array URGENCY_SHORT_LABEL_KEYS = [
        1 => 'stats.overview.hospital_summary.urgency_u1',
        2 => 'stats.overview.hospital_summary.urgency_u2',
        3 => 'stats.overview.hospital_summary.urgency_u3',
    ];

    /**
     * Age bucket display order (must stay in sync with the CASE expression in PivotAllocationAggregationQuery).
     *
     * @var list<string>
     */
    private const array AGE_BUCKET_ORDER = [
        'unknown', '0_18', '19_29', '30_39', '40_49', '50_59', '60_69', '70_79', '80_89', '90_99', '100p',
    ];

    /** PHP casts numeric string keys ("5") to int — use a prefix for associative maps. */
    private const string ASSOC_ROW_PREFIX = 'pivot_row:';

    private const string ASSOC_COL_PREFIX = 'pivot_col:';

    public function __construct(
        private PivotAllocationAggregationQuery $pivotAllocationAggregationQuery,
        private StatisticsScopeResolver $scopeResolver,
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return 'pivot';
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.analysis.pivot.label';
    }

    #[\Override]
    public function supports(StatisticsContext $context): bool
    {
        return true;
    }

    #[\Override]
    public function build(
        StatisticsContext $context,
        string $view,
        string $chartType,
        StatisticsAnalysisDimension $dimension,
        StatisticsChartMeasure $chartMeasure = StatisticsChartMeasure::Absolute,
    ): StatisticWidget {
        unset($view, $chartType, $dimension, $chartMeasure);

        $axes = $context->pivotAxes ?? PivotTableAxes::default();
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $hospitalIds = $this->hospitalIdsOrNull($context);

        $cells = $this->pivotAllocationAggregationQuery->fetchCells(
            $bounds->from,
            $bounds->toExclusive,
            $hospitalIds,
            $axes->row,
            $axes->col,
        );

        $payload = $this->buildPivotPayload($cells, $axes);

        return new StatisticWidget(StatisticWidgetType::PivotTable, 'pivot_table', $payload);
    }

    /**
     * @param list<array{row_key: string, col_key: string, cnt: int, row_label?: string}> $cells
     *
     * @return array<string, mixed>
     */
    private function buildPivotPayload(array $cells, PivotTableAxes $axes): array
    {
        $cells = $this->normalizePivotCellKeys($cells);

        $rowLabelsMap = [];
        foreach ($cells as $cell) {
            $rk = $cell['row_key'];
            $rkAssoc = $this->assocRowKey($rk);
            if (!isset($rowLabelsMap[$rkAssoc]) && isset($cell['row_label'])) {
                $rowLabelsMap[$rkAssoc] = $cell['row_label'];
            }
        }

        $rowKeys = $this->sortedRowKeys($cells, $axes->row, $rowLabelsMap);
        $colKeys = $this->sortedColKeys($cells, $axes->col);

        /** @var array<string, array<string, int>> $byRowCol */
        $byRowCol = [];
        foreach ($cells as $cell) {
            $rk = $cell['row_key'];
            $ck = $cell['col_key'];
            $rkAssoc = $this->assocRowKey($rk);
            $ckAssoc = $this->assocColKey($ck);
            if (!isset($byRowCol[$rkAssoc])) {
                $byRowCol[$rkAssoc] = [];
            }
            $byRowCol[$rkAssoc][$ckAssoc] = $cell['cnt'];
        }

        $matrix = [];
        $rowTotals = [];
        foreach ($rowKeys as $rk) {
            $row = [];
            $sum = 0;
            foreach ($colKeys as $ck) {
                $v = $byRowCol[$this->assocRowKey($rk)][$this->assocColKey($ck)] ?? 0;
                $row[] = $v;
                $sum += $v;
            }
            $matrix[] = $row;
            $rowTotals[] = $sum;
        }

        $columnTotals = [];
        $grand = 0;
        foreach (array_keys($colKeys) as $ci) {
            $cSum = 0;
            foreach (array_keys($rowKeys) as $ri) {
                $cSum += $matrix[$ri][$ci] ?? 0;
            }
            $columnTotals[] = $cSum;
            $grand += $cSum;
        }

        $rowDisplay = [];
        foreach ($rowKeys as $rk) {
            $rowDisplay[] = $this->formatRowHeader($rk, $axes->row, $rowLabelsMap);
        }

        $colDisplay = [];
        foreach ($colKeys as $ck) {
            $colDisplay[] = $this->formatColHeader($ck, $axes->col);
        }

        return [
            'rowDimensionLabel' => $this->translator->trans($this->rowAxisLabelKey($axes->row)),
            'columnDimensionLabel' => $this->translator->trans($this->colAxisLabelKey($axes->col)),
            'rowLabels' => $rowDisplay,
            'columnLabels' => $colDisplay,
            'matrix' => $matrix,
            'row_totals' => $rowTotals,
            'column_totals' => $columnTotals,
            'grand_total' => $grand,
            'rowTotalHeaderLabel' => $this->translator->trans('stats.analysis.pivot.row_total'),
            'columnTotalFooterLabel' => $this->translator->trans('stats.analysis.pivot.column_total'),
            'grandTotalFooterLabel' => $this->translator->trans('stats.analysis.pivot.grand_total'),
        ];
    }

    /**
     * Doctrine may return numeric IDs as int; PHP then uses int array keys — normalize everything to string here.
     *
     * @param list<array<string, mixed>> $cells
     *
     * @return list<array{row_key: string, col_key: string, cnt: int, row_label?: string}>
     */
    private function normalizePivotCellKeys(array $cells): array
    {
        $out = [];
        foreach ($cells as $cell) {
            $row = [
                'row_key' => (string) ($cell['row_key'] ?? ''),
                'col_key' => (string) ($cell['col_key'] ?? ''),
                'cnt' => (int) ($cell['cnt'] ?? 0),
            ];
            if (isset($cell['row_label'])) {
                $row['row_label'] = (string) $cell['row_label'];
            }
            $out[] = $row;
        }

        return $out;
    }

    private function assocRowKey(string $rowKey): string
    {
        return self::ASSOC_ROW_PREFIX.$rowKey;
    }

    private function assocColKey(string $colKey): string
    {
        return self::ASSOC_COL_PREFIX.$colKey;
    }

    private function rowAxisLabelKey(PivotRowAxis $row): string
    {
        return match ($row) {
            PivotRowAxis::Department => 'stats.analysis.pivot.axis.rows.department',
            PivotRowAxis::AgeGroup => 'stats.analysis.pivot.axis.rows.age_group',
            PivotRowAxis::Urgency => 'stats.analysis.pivot.axis.rows.urgency',
        };
    }

    private function colAxisLabelKey(PivotColAxis $col): string
    {
        return match ($col) {
            PivotColAxis::Gender => 'stats.analysis.pivot.axis.cols.gender',
            PivotColAxis::Urgency => 'stats.analysis.pivot.axis.cols.urgency',
        };
    }

    /**
     * @param array<string, string> $rowLabelsMap Keys: {@see assocRowKey()}
     */
    private function formatRowHeader(string $rowKey, PivotRowAxis $row, array $rowLabelsMap): string
    {
        return match ($row) {
            PivotRowAxis::Department => $rowLabelsMap[$this->assocRowKey($rowKey)] ?? $rowKey,
            PivotRowAxis::AgeGroup => $this->translator->trans($this->ageBucketTranslationKey($rowKey)),
            PivotRowAxis::Urgency => $this->translator->trans(self::URGENCY_SHORT_LABEL_KEYS[(int) $rowKey] ?? 'stats.analysis.pivot.unknown'),
        };
    }

    private function formatColHeader(string $colKey, PivotColAxis $col): string
    {
        return match ($col) {
            PivotColAxis::Gender => $this->translator->trans(
                AllocationGender::tryFrom($colKey)?->label() ?? 'stats.analysis.pivot.unknown',
            ),
            PivotColAxis::Urgency => $this->translator->trans(
                self::URGENCY_SHORT_LABEL_KEYS[(int) $colKey] ?? 'stats.analysis.pivot.unknown',
            ),
        };
    }

    private function ageBucketTranslationKey(string $key): string
    {
        return match ($key) {
            'unknown' => 'stats.analysis.pivot.age.unknown',
            '0_18' => 'stats.analysis.pivot.age.0_18',
            '19_29' => 'stats.analysis.pivot.age.19_29',
            '30_39' => 'stats.analysis.pivot.age.30_39',
            '40_49' => 'stats.analysis.pivot.age.40_49',
            '50_59' => 'stats.analysis.pivot.age.50_59',
            '60_69' => 'stats.analysis.pivot.age.60_69',
            '70_79' => 'stats.analysis.pivot.age.70_79',
            '80_89' => 'stats.analysis.pivot.age.80_89',
            '90_99' => 'stats.analysis.pivot.age.90_99',
            '100p' => 'stats.analysis.pivot.age.100_plus',
            default => 'stats.analysis.pivot.unknown',
        };
    }

    /**
     * @param list<array{row_key: string, col_key: string, cnt: int, row_label?: string}> $cells
     * @param array<string, string>                                                       $rowLabelsMap Keys: {@see assocRowKey()}
     *
     * @return list<string>
     */
    private function sortedRowKeys(array $cells, PivotRowAxis $rowAxis, array $rowLabelsMap): array
    {
        $seen = [];
        $unique = [];
        foreach ($cells as $cell) {
            $rk = $cell['row_key'];
            $mk = $this->assocRowKey($rk);
            if (isset($seen[$mk])) {
                continue;
            }
            $seen[$mk] = true;
            $unique[] = $rk;
        }

        if ([] === $unique) {
            return match ($rowAxis) {
                PivotRowAxis::Urgency => array_map(
                    static fn (AllocationUrgency $u): string => (string) $u->value,
                    AllocationUrgency::cases(),
                ),
                PivotRowAxis::AgeGroup => self::AGE_BUCKET_ORDER,
                PivotRowAxis::Department => [],
            };
        }

        return match ($rowAxis) {
            PivotRowAxis::Department => $this->sortDepartmentRowKeys($unique, $rowLabelsMap),
            PivotRowAxis::AgeGroup => $this->sortAgeRowKeys($unique),
            PivotRowAxis::Urgency => $this->sortUrgencyLikeKeys($unique),
        };
    }

    /**
     * @param list<string>          $unique
     * @param array<string, string> $rowLabelsMap Keys: {@see assocRowKey()}
     *
     * @return list<string>
     */
    private function sortDepartmentRowKeys(array $unique, array $rowLabelsMap): array
    {
        usort($unique, function (string $a, string $b) use ($rowLabelsMap): int {
            $la = $rowLabelsMap[$this->assocRowKey($a)] ?? $a;
            $lb = $rowLabelsMap[$this->assocRowKey($b)] ?? $b;

            return strcasecmp($la, $lb);
        });

        return $unique;
    }

    /**
     * @param list<string> $unique
     *
     * @return list<string>
     */
    private function sortAgeRowKeys(array $unique): array
    {
        $order = array_flip(self::AGE_BUCKET_ORDER);
        usort($unique, static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return $unique;
    }

    /**
     * @param list<string> $unique
     *
     * @return list<string>
     */
    private function sortUrgencyLikeKeys(array $unique): array
    {
        usort($unique, static fn (string $a, string $b): int => (int) $a <=> (int) $b);

        return $unique;
    }

    /**
     * @param list<array{row_key: string, col_key: string, cnt: int, row_label?: string}> $cells
     *
     * @return list<string>
     */
    private function sortedColKeys(array $cells, PivotColAxis $colAxis): array
    {
        $seen = [];
        $unique = [];
        foreach ($cells as $cell) {
            $ck = $cell['col_key'];
            $mk = $this->assocColKey($ck);
            if (isset($seen[$mk])) {
                continue;
            }
            $seen[$mk] = true;
            $unique[] = $ck;
        }

        if ([] === $unique) {
            return match ($colAxis) {
                PivotColAxis::Gender => array_map(
                    static fn (AllocationGender $g): string => $g->value,
                    AllocationGender::cases(),
                ),
                PivotColAxis::Urgency => array_map(
                    static fn (AllocationUrgency $u): string => (string) $u->value,
                    AllocationUrgency::cases(),
                ),
            };
        }

        return match ($colAxis) {
            PivotColAxis::Gender => $this->sortGenderColKeys($unique),
            PivotColAxis::Urgency => $this->sortUrgencyLikeKeys($unique),
        };
    }

    /**
     * @param list<string> $unique
     *
     * @return list<string>
     */
    private function sortGenderColKeys(array $unique): array
    {
        $order = [];
        foreach (AllocationGender::cases() as $i => $g) {
            $order[$g->value] = $i;
        }
        usort($unique, static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return $unique;
    }

    /**
     * @return list<int>|null
     */
    private function hospitalIdsOrNull(StatisticsContext $context): ?array
    {
        return $this->scopeResolver->hospitalIdsOrNull($context);
    }
}
