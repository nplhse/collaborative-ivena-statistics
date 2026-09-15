<?php

declare(strict_types=1);

namespace App\Statistics\GeographicMap\Application\DTO;

enum GeographicMapLayer: string
{
    case OriginChoropleth = 'originChoropleth';
    case DestinationHospitals = 'destinationHospitals';
    case IsochroneBands = 'isochroneBands';
    case HospitalPin = 'hospitalPin';
}
