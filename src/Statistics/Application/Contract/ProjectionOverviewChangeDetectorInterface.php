<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

interface ProjectionOverviewChangeDetectorInterface
{
    public function willIntroduceNewHospitals(int $importId): bool;

    public function willRemoveHospitalsFromProjection(int $importId): bool;
}
