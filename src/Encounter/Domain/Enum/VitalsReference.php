<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Time reference of the initial vitals, `vitals_reference` of the `ed_visit` exchange format (#1047).
 */
enum VitalsReference: string
{
    case AdmissionMeasurement = 'admission_measurement';
    case DocumentedInitial = 'documented_initial';
}
