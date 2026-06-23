<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum ExplorerDistributionValueSource: string
{
    case HospitalBeds = 'hospital_beds';
    case AllocationsPerHospital = 'allocations_per_hospital';
}
