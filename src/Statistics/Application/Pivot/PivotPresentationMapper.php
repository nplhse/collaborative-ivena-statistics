<?php

declare(strict_types=1);

namespace App\Statistics\Application\Pivot;

final class PivotPresentationMapper
{
    /**
     * @param callable(float): string $formatter
     */
    public function map(PivotResult $pivot, bool $isRowPercent, callable $formatter): PivotPresentationPayload
    {
        $matrix = [];
        $rowTotals = $pivot->rowTotals;
        foreach ($pivot->matrix as $rowIndex => $rowValues) {
            $formattedRow = [];
            foreach ($rowValues as $value) {
                if ($isRowPercent) {
                    $denominator = $rowTotals[$rowIndex] ?? 0.0;
                    $pct = $denominator > 0.0 ? round(($value / $denominator) * 100.0, 1) : 0.0;
                    $formattedRow[] = sprintf('%.1f%%', $pct);
                    continue;
                }
                $formattedRow[] = $formatter($value);
            }
            $matrix[] = $formattedRow;
        }

        $rowTotalsOut = array_map($formatter, $pivot->rowTotals);
        $colTotalsOut = array_map($formatter, $pivot->columnTotals);
        $grandTotalOut = $formatter($pivot->grandTotal);

        if ($isRowPercent) {
            $rowTotalsOut = array_fill(0, \count($pivot->rowTotals), '100.0%');
            $colTotalsOut = array_fill(0, \count($pivot->columnTotals), '100.0%');
            $grandTotalOut = '100.0%';
        }

        return new PivotPresentationPayload(
            $matrix,
            $rowTotalsOut,
            $colTotalsOut,
            $grandTotalOut,
        );
    }
}
