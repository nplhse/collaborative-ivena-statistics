<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Reader;

use App\Statistics\Domain\Model\DashboardPanelView;
use App\Statistics\Domain\Model\Scope;

interface TimeGridSeriesReaderInterface
{
    /**
     * @param array<int, array{label:string,periodKey:string,isTotal?:bool}> $columns
     *
     * @return array<string, DashboardPanelView|null> map[periodKey] => view|null
     */
    public function loadSeries(Scope $scope, array $columns): array;
}
