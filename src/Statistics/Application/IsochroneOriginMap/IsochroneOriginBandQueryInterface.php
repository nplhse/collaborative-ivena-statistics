<?php

declare(strict_types=1);

namespace App\Statistics\Application\IsochroneOriginMap;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\IsochroneOriginMap\Dto\IsochroneOriginBandQueryResult;

interface IsochroneOriginBandQueryInterface
{
    /**
     * @param list<int>|null $indicationIds null = no indication filter; empty = no rows
     */
    public function fetch(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?array $indicationIds = null,
    ): IsochroneOriginBandQueryResult;
}
