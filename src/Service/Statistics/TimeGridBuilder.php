<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Enum\TimeGridMode;
use App\Model\Scope;
use App\Model\TimeGridCell;
use App\Service\Statistics\Util\Period;
use App\Service\Statistics\Util\TimeGrid;

final readonly class TimeGridBuilder
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private TimeGridSeriesReader $seriesReader,
    ) {
    }

    /**
     * @param list<array{label:string,key:string,format:'int'|'pct'}> $metrics
     *
     * @return array{
     *   columns: list<array{label:string,periodKey:string,isTotal?:bool}>,
     *   rows:    list<array{label:string,format:'int'|'pct',cells:list<TimeGridCell>}>
     * }
     */
    public function build(
        Scope $primary,
        array $metrics,
        TimeGridMode $mode,
        ?Scope $base = null,
    ): array {
        $anchor = Period::anchor($primary->granularity, $primary->periodKey);
        $allCols = TimeGrid::columns($primary->granularity, $anchor);
        $timeCols = array_values(array_filter($allCols, static fn (array $c) => !($c['isTotal'] ?? false)));
        $columns = [...$timeCols, ['label' => 'Total', 'periodKey' => 'TOTAL', 'isTotal' => true]];

        $primarySeries = $this->seriesReader->loadSeries($primary, $columns);

        $baseSeries = null;
        if (TimeGridMode::COMPARE === $mode && $base instanceof Scope) {
            $baseSeries = $this->seriesReader->loadSeries($base, $columns);
        }

        $rows = [];
        foreach ($metrics as $spec) {
            $key = $spec['key'];
            $label = $spec['label'];
            $format = $spec['format'];

            $cells = [];
            $prevNumeric = null;
            $baseValsForTotal = [];

            foreach ($timeCols as $col) {
                $pk = $col['periodKey'];
                $view = $primarySeries[$pk] ?? null;
                $val = $view->$key ?? null;

                $valNum = is_numeric($val) ? (float) $val : null;

                $compare = null;
                if (TimeGridMode::COMPARE === $mode && null !== $baseSeries) {
                    $bView = $baseSeries[$pk] ?? null;
                    $bVal = $bView->$key ?? null;
                    $compare = is_numeric($bVal) ? (float) $bVal : null;
                }

                $deltaAbs = null;
                $deltaPct = null;

                if (TimeGridMode::DELTA === $mode) {
                    if (is_numeric($valNum) && is_numeric($prevNumeric)) {
                        $deltaAbs = $valNum - $prevNumeric;
                        $deltaPct = (0.0 !== $prevNumeric)
                            ? round(100.0 * ($deltaAbs / $prevNumeric), 1)
                            : null;
                    }
                    $prevNumeric = is_numeric($valNum) ? $valNum : $prevNumeric;
                } elseif (TimeGridMode::COMPARE === $mode) {
                    if (is_numeric($valNum) && is_numeric($compare)) {
                        $deltaAbs = $valNum - $compare;
                        $deltaPct = (0.0 !== $compare)
                            ? round(100.0 * ($deltaAbs / $compare), 1)
                            : null;
                    }
                }

                // keep baseline numbers to compute the Total-cell baseline summary
                $baseValsForTotal[] = (is_numeric($compare) ? $compare : null);

                $cells[] = new TimeGridCell(
                    value: $valNum,
                    deltaAbs: $deltaAbs,
                    deltaPct: $deltaPct,
                    compare: $compare,
                    stats: null
                );
            }

            // build the final “Total” cell for this row
            $totalCell = $this->buildRowTotalCell(
                timeCells: $cells,
                format: $format,
                mode: $mode,
                baseVals: $baseValsForTotal
            );
            $cells[] = $totalCell;

            $rows[] = [
                'label' => $label,
                'format' => $format,
                'cells' => $cells,
            ];
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Builds the per-row Total cell:
     * - format 'int' => sum of numeric time cells
     * - format 'pct' => mean of numeric time cells
     * If COMPARE mode is active and baseline values are provided, the total cell will also
     * include `compare` (baseline summary) and `delta*` vs. baseline summary.
     *
     * @param list<TimeGridCell>   $timeCells
     * @param list<float|int|null> $baseVals
     */
    private function buildRowTotalCell(
        array $timeCells,
        string $format,
        TimeGridMode $mode,
        ?array $baseVals = null,
    ): TimeGridCell {
        // primary summary
        if ('pct' === $format) {
            $sum = 0.0;
            $cnt = 0;
            foreach ($timeCells as $c) {
                if (is_numeric($c->value)) {
                    $sum += (float) $c->value;
                    ++$cnt;
                }
            }
            $primaryTotal = $cnt > 0 ? round($sum / (float) $cnt, 1) : null;
        } else {
            $sum = 0.0;
            $has = false;
            foreach ($timeCells as $c) {
                if (is_numeric($c->value)) {
                    $sum += (float) $c->value;
                    $has = true;
                }
            }
            $primaryTotal = $has ? (int) round($sum) : null;
        }

        // baseline summary (only for COMPARE)
        $compareTotal = null;
        $deltaAbs = null;
        $deltaPct = null;

        if (TimeGridMode::COMPARE === $mode && \is_array($baseVals)) {
            if ('pct' === $format) {
                $sum = 0.0;
                $cnt = 0;
                foreach ($baseVals as $bv) {
                    if (is_numeric($bv)) {
                        $sum += (float) $bv;
                        ++$cnt;
                    }
                }
                $compareTotal = $cnt > 0 ? round($sum / (float) $cnt, 1) : null;
            } else {
                $sum = 0.0;
                $has = false;
                foreach ($baseVals as $bv) {
                    if (is_numeric($bv)) {
                        $sum += (float) $bv;
                        $has = true;
                    }
                }
                $compareTotal = $has ? (int) round($sum) : null;
            }

            if (is_numeric($primaryTotal) && is_numeric($compareTotal)) {
                $deltaAbs = (float) $primaryTotal - (float) $compareTotal;
                $cmp = (float) $compareTotal;
                $deltaPct = (0.0 !== $cmp)
                        ? round(100.0 * ($deltaAbs / $cmp), 1)
                        : null;
            }
        }

        return new TimeGridCell(
            value: $primaryTotal,
            deltaAbs: $deltaAbs,
            deltaPct: $deltaPct,
            compare: $compareTotal,
            stats: null
        );
    }
}
