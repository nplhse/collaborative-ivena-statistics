<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

/**
 * Maps catalog detail objects to allocation_stats_projection filter columns.
 */
enum CatalogDimensionKey: string
{
    case SecondaryTransport = 'secondary_transport';
    case Indication = 'indication';
    case Department = 'department';
    case Speciality = 'speciality';
    case Assignment = 'assignment';
    case Occasion = 'occasion';
    case Infection = 'infection';
    case DispatchArea = 'dispatch_area';
    case State = 'state';
    case Hospital = 'hospital';

    public function projectionColumn(): string
    {
        return match ($this) {
            self::SecondaryTransport => 'secondary_transport_id',
            self::Indication => 'indication_normalized_id',
            self::Department => 'department_id',
            self::Speciality => 'speciality_id',
            self::Assignment => 'assignment_id',
            self::Occasion => 'occasion_id',
            self::Infection => 'infection_id',
            self::DispatchArea => 'dispatch_area_id',
            self::State => 'state_id',
            self::Hospital => 'hospital_id',
        };
    }
}
