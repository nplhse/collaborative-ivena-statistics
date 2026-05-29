<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

final readonly class StatisticsFilterInput
{
    public function __construct(
        public string $scope,
        public string $hospital,
        public string $cohort,
        public string $state,
        public string $dispatchArea,
        public string $period,
        public mixed $year,
        public mixed $month,
        public mixed $quarter,
        public bool $hasScopeQueryParameter,
    ) {
    }
}
