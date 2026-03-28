<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

use App\Statistics\Domain\Model\DistributionPanelView;

/**
 * Pivots SQL aggregate rows into chart/table structures; percentages are derived from counts only.
 */
final class DistributionTransformer
{
    /**
     * @param list<array{pk: int, gk: int|null, value: int}> $rows
     */
    public function transform(
        array $rows,
        CodeLabelMapperInterface $primaryMapper,
        ?CodeLabelMapperInterface $groupMapper,
        bool $grouped,
        string $simpleSeriesName,
    ): DistributionPanelView {
        if (!$grouped || !$groupMapper instanceof CodeLabelMapperInterface) {
            return $this->transformSimple($rows, $primaryMapper, $simpleSeriesName);
        }

        return $this->transformGrouped($rows, $primaryMapper, $groupMapper);
    }

    /**
     * @param list<array{pk: int, gk: int|null, value: int}> $rows
     */
    private function transformSimple(
        array $rows,
        CodeLabelMapperInterface $primaryMapper,
        string $simpleSeriesName,
    ): DistributionPanelView {
        $byPk = [];
        foreach ($rows as $r) {
            $pk = $r['pk'];
            $byPk[$pk] = ($byPk[$pk] ?? 0) + $r['value'];
        }

        $pks = array_keys($byPk);
        sort($pks, SORT_NUMERIC);

        $labels = [];
        $values = [];
        foreach ($pks as $pk) {
            $labels[] = $primaryMapper->label($pk);
            $values[] = $byPk[$pk];
        }

        $total = array_sum($values);
        $totalFloat = (float) $total;
        $percentages = array_map(
            static fn (int $v): float => $totalFloat > 0.0 ? round((float) $v / $totalFloat * 100.0, 2) : 0.0,
            $values
        );

        $tableRows = [];
        foreach (array_keys($pks) as $i) {
            $tableRows[] = [
                'primaryLabel' => $labels[$i],
                'groupLabel' => null,
                'count' => $values[$i],
                'percent' => $percentages[$i],
            ];
        }

        return new DistributionPanelView(
            $labels,
            [
                [
                    'name' => $simpleSeriesName,
                    'values' => $values,
                    'percentages' => $percentages,
                ],
            ],
            $tableRows,
            false,
        );
    }

    /**
     * @param list<array{pk: int, gk: int|null, value: int}> $rows
     */
    private function transformGrouped(
        array $rows,
        CodeLabelMapperInterface $primaryMapper,
        CodeLabelMapperInterface $groupMapper,
    ): DistributionPanelView {
        $matrix = [];
        $pkSet = [];
        $gkNormSet = [];

        foreach ($rows as $r) {
            $pk = $r['pk'];
            $gk = $r['gk'];
            $gkNorm = $this->normalizeGroupKey($gk);
            $pkSet[$pk] = true;
            $gkNormSet[$gkNorm] = true;
            $matrix[$gkNorm] ??= [];
            $matrix[$gkNorm][$pk] = ($matrix[$gkNorm][$pk] ?? 0) + $r['value'];
        }

        $pks = array_keys($pkSet);
        sort($pks, SORT_NUMERIC);

        $gkNorms = array_keys($gkNormSet);
        usort($gkNorms, static function (string $a, string $b): int {
            if ($a === $b) {
                return 0;
            }
            if ('__null__' === $a) {
                return 1;
            }
            if ('__null__' === $b) {
                return -1;
            }

            return (int) substr($a, 1) <=> (int) substr($b, 1);
        });

        $labels = array_map($primaryMapper->label(...), $pks);

        $rowTotals = [];
        foreach ($pks as $i => $pk) {
            $sum = 0;
            foreach ($gkNorms as $gkNorm) {
                $sum += (int) ($matrix[$gkNorm][$pk] ?? 0);
            }
            $rowTotals[$i] = $sum;
        }

        $series = [];
        foreach ($gkNorms as $gkNorm) {
            $values = [];
            $percentages = [];
            foreach ($pks as $i => $pk) {
                $v = (int) ($matrix[$gkNorm][$pk] ?? 0);
                $values[] = $v;
                $rt = $rowTotals[$i];
                $rtFloat = (float) $rt;
                $percentages[] = $rtFloat > 0.0 ? round((float) $v / $rtFloat * 100.0, 2) : 0.0;
            }
            $series[] = [
                'name' => $groupMapper->label($this->denormalizeGroupKey($gkNorm)),
                'values' => $values,
                'percentages' => $percentages,
            ];
        }

        $tableRows = [];
        foreach ($rows as $r) {
            $pk = $r['pk'];
            $gk = $r['gk'];
            $v = $r['value'];
            $pkIndex = array_search($pk, $pks, true);
            $rt = false !== $pkIndex ? $rowTotals[$pkIndex] : 0;
            $rtFloat = (float) $rt;
            $pct = $rtFloat > 0.0 ? round((float) $v / $rtFloat * 100.0, 2) : 0.0;
            $tableRows[] = [
                'primaryLabel' => $primaryMapper->label($pk),
                'groupLabel' => $groupMapper->label($gk),
                'count' => $v,
                'percent' => $pct,
            ];
        }

        return new DistributionPanelView($labels, $series, $tableRows, true);
    }

    private function normalizeGroupKey(?int $gk): string
    {
        return null === $gk ? '__null__' : 'k'.$gk;
    }

    private function denormalizeGroupKey(string $gkNorm): ?int
    {
        return '__null__' === $gkNorm ? null : (int) substr($gkNorm, 1);
    }
}
