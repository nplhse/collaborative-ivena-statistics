<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum ExplorerDistributionValueSource: string
{
    case HospitalBeds = 'hospital_beds';
    case AllocationsPerHospital = 'allocations_per_hospital';
    case AllocationTransportTime = 'allocation_transport_time';
    case HospitalMedianTransportTime = 'hospital_median_transport_time';
}
