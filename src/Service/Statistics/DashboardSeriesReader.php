<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Model\DashboardPanelView;
use App\Model\Scope;
use App\Service\Statistics\Util\Period;

final readonly class DashboardSeriesReader
{
    public function __construct(private DashboardCountsReader $counts)
    {
    }

    /**
     * @param array<int, array{label: string, periodKey: string, isTotal?: bool}> $columns
     *
     * @return array<string, DashboardPanelView|null> map[periodKey] => view|null
     */
    public function loadSeries(Scope $scope, array $columns): array
    {
        // Build the list of period keys (skipping TOTAL)
        $keys = [];
        foreach ($columns as $c) {
            if (($c['isTotal'] ?? false) !== true) {
                $keys[] = $c['periodKey'];
            }
        }

        // Determine the data granularity (finer than UI granularity)
        $dataGran = match ($scope->granularity) {
            Period::YEAR => Period::MONTH,
            Period::QUARTER => Period::MONTH,
            Period::MONTH => Period::DAY,
            Period::WEEK => Period::DAY,
            Period::DAY => Period::DAY,
            Period::ALL => Period::ALL,
            default => $scope->granularity,
        };

        // Use a scope with different (finer) granularity for the fetch
        $dataScope = new Scope(
            $scope->scopeType,
            $scope->scopeId,
            $dataGran,
            $scope->periodKey     // this is irrelevant in readMany
        );

        return $this->counts->readMany($dataScope, $keys);
    }
}
