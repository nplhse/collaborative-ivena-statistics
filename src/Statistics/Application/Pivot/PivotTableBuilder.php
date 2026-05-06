<?php

declare(strict_types=1);

namespace App\Statistics\Application\Pivot;

final class PivotTableBuilder
{
    /**
     * @param list<string>                               $rowKeys
     * @param list<string>                               $colKeys
     * @param list<array{row_key: string, col_key: string, value: float}> $cells
     * @param array<string, string>                      $rowLabels
     * @param array<string, string>                      $colLabels
     */
    public function build(array $rowKeys, array $colKeys, array $cells, array $rowLabels, array $colLabels): PivotResult
    {
        $index = [];
        foreach ($cells as $cell) {
            $index[$cell['row_key'].'|'.$cell['col_key']] = $cell['value'];
        }

        $matrix = [];
        $rowTotals = [];
        foreach ($rowKeys as $rowKey) {
            $row = [];
            $rowTotal = 0.0;
            foreach ($colKeys as $colKey) {
                $value = (float) ($index[$rowKey.'|'.$colKey] ?? 0.0);
                $row[] = $value;
                $rowTotal += $value;
            }
            $matrix[] = $row;
            $rowTotals[] = $rowTotal;
        }

        $columnTotals = [];
        $grandTotal = 0.0;
        foreach (array_keys($colKeys) as $colIndex) {
            $sum = 0.0;
            foreach (array_keys($rowKeys) as $rowIndex) {
                $sum += $matrix[$rowIndex][$colIndex] ?? 0.0;
            }
            $columnTotals[] = $sum;
            $grandTotal += $sum;
        }

        $rowLabelList = [];
        foreach ($rowKeys as $key) {
            $rowLabelList[] = $rowLabels[$key] ?? $key;
        }
        $colLabelList = [];
        foreach ($colKeys as $key) {
            $colLabelList[] = $colLabels[$key] ?? $key;
        }

        return new PivotResult($rowLabelList, $colLabelList, $matrix, $rowTotals, $columnTotals, $grandTotal);
    }
}
