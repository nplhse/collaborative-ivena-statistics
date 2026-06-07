<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;

final class CaseFlowModeResolver
{
    public function resolve(StatisticsFilter $filter): CaseFlowMode
    {
        if (StatisticsFilterScope::Hospital === $filter->scope || StatisticsFilterScope::MyHospitals === $filter->scope) {
            return CaseFlowMode::HospitalOrigin;
        }

        return CaseFlowMode::SystemFlow;
    }
}
