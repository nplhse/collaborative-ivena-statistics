<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

enum CaseFlowMode: string
{
    case SystemFlow = 'system_flow';
    case HospitalOrigin = 'hospital_origin';
}
