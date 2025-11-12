<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Model\DashboardPanelView;
use App\Model\Scope;
use App\Service\Statistics\Util\Period;

final readonly class TimeGridSeriesReader
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private DashboardCountsReader $counts,
    ) {
    }

    /**
     * @param array<int, array{label:string,periodKey:string,isTotal?:bool}> $columns
     *
     * @return array<string, DashboardPanelView|null> map[periodKey] => view|null
     */
    public function loadSeries(Scope $scope, array $columns): array
    {
        $keys = [];
        foreach ($columns as $c) {
            if (($c['isTotal'] ?? false) !== true) {
                $keys[] = $c['periodKey'];
            }
        }

        $dataGran = $this->mapGranularity($scope->granularity);

        $dataScope = new Scope(
            $scope->scopeType,
            $scope->scopeId,
            $dataGran,
            $scope->periodKey
        );

        return $this->counts->readMany($dataScope, $keys);
    }

    private function mapGranularity(string $uiGranularity): string
    {
        return match ($uiGranularity) {
            Period::YEAR => Period::MONTH,
            Period::QUARTER => Period::MONTH,
            Period::MONTH => Period::DAY,
            Period::WEEK => Period::DAY,
            Period::DAY => Period::DAY,
            Period::ALL => Period::ALL,
            default => $uiGranularity,
        };
    }
}
