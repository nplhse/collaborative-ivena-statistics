<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Domain\Enum;

/**
 * Documented arrival mode, `arrival_mode` of the `ed_visit` exchange format (#1047).
 */
enum ArrivalMode: string
{
    case Self = 'self';
    case Ambulance = 'ambulance';
    case PatientTransport = 'patient_transport';
    case PhysicianVehicle = 'physician_vehicle';
    case Other = 'other';
}
