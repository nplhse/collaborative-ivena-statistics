<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Documented arrival route of an ED visit, derived from the arrival mode (#1047).
 *
 * Separate from the IVENA link status: a missing link says nothing about the arrival route (ADR 025).
 */
enum ArrivalRoute: string
{
    case Ems = 'ems';
    case Self = 'self';
    case Unknown = 'unknown';

    /**
     * EMS = ambulance (RTW), patient transport (KTW) and physician vehicle (NAW/NEF/ITW, usually an
     * RTW with an emergency physician); `self` = self-presenter; `other` and empty = unknown.
     */
    public static function fromArrivalMode(?ArrivalMode $mode): self
    {
        return match ($mode) {
            ArrivalMode::Ambulance, ArrivalMode::PatientTransport, ArrivalMode::PhysicianVehicle => self::Ems,
            ArrivalMode::Self => self::Self,
            ArrivalMode::Other, null => self::Unknown,
        };
    }
}
