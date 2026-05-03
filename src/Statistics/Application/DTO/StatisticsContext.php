<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

use App\User\Domain\Entity\User;

final readonly class StatisticsContext
{
    public function __construct(
        public ?User $user,
        public StatisticsFilter $filter,
        public ?PivotTableAxes $pivotAxes = null,
    ) {
    }
}
