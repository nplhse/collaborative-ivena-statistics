<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\User\Domain\Entity\User;

final readonly class DataQualityCriteria
{
    public function __construct(
        public int $indicationId,
        public StatisticsFilter $filter,
        public StatisticsScopeCriteria $scope,
        public StatisticsPeriodBounds $period,
        public string $scopeLabel,
        public string $periodLabel,
        public ?User $user,
    ) {
    }
}
