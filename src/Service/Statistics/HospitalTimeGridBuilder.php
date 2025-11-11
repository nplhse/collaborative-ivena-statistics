<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Model\DashboardPanelView;
use App\Model\Scope;
use App\Service\Statistics\Util\Period;
use App\Service\Statistics\Util\TimeGrid;

final readonly class HospitalTimeGridBuilder
{
    public function __construct(private DashboardSeriesReader $seriesReader)
    {
    }

    /**
     * @param list<array{label:string,key:string,format:'int'|'pct'}> $metricDefs
     *
     * @return array{
     *   columns: list<array{label:string,periodKey:string}>,
     *   rows:    list<array{label:string,values:list<int|float|null>,format:'int'|'pct'}>
     * }
     */
    public function build(Scope $scope, array $metricDefs): array
    {
        $anchor = Period::anchor($scope->granularity, $scope->periodKey);

        // Build all columns (including potential TOTAL placeholder), then filter to "real" time columns:
        $allCols = TimeGrid::columns($scope->granularity, $anchor);
        $cols = array_values(array_filter($allCols, static fn ($c) => !($c['isTotal'] ?? false)));

        // Load series just for the real period keys:
        $series = $this->seriesReader->loadSeries($scope, $cols);

        $rows = [];
        foreach ($metricDefs as $def) {
            $values = [];
            $sum = 0.0;
            $cnt = 0;

            foreach ($cols as $col) {
                $view = $series[$col['periodKey']] ?? null;
                $val = self::getMetricValue($view, $def['key']); // may be null

                $values[] = $val;

                if (null !== $val && is_numeric($val)) {
                    if ('pct' === $def['format']) {
                        $sum += (float) $val;
                        ++$cnt;
                    } else {
                        $sum += (float) $val;
                    }
                }
            }

            // Append computed Total cell per row (sum for ints, average for pct)
            $totalCell = 'pct' === $def['format']
                ? ($cnt > 0 ? round($sum / $cnt, 1) : null)
                : (0.0 !== $sum ? (int) round($sum) : null);

            $values[] = $totalCell;

            $rows[] = [
                'label' => $def['label'],
                'values' => $values,
                'format' => $def['format'],
            ];
        }

        return ['columns' => $cols, 'rows' => $rows];
    }

    private static function getMetricValue(?DashboardPanelView $view, string $key): int|float|null
    {
        if (!$view) {
            return null;
        }

        // Support both simple property names ("total", "pctMale")
        // and dot-notation if you ever need it ("some.nested.key")
        if (str_contains($key, '.')) {
            $current = $view;
            foreach (explode('.', $key) as $segment) {
                if (is_object($current) && isset($current->{$segment})) {
                    $current = $current->{$segment};
                } elseif (is_array($current) && array_key_exists($segment, $current)) {
                    $current = $current[$segment];
                } else {
                    return null;
                }
            }

            return is_numeric($current) ? (float) $current : (is_int($current) ? $current : null);
        }

        return isset($view->{$key}) && is_numeric($view->{$key})
            ? (float) $view->{$key}
            : (isset($view->{$key}) && is_int($view->{$key}) ? $view->{$key} : null);
    }
}
