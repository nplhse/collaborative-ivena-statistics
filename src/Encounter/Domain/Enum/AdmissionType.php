<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * `admission_type` of the `ed_visit` exchange format (#1047).
 */
enum AdmissionType: string
{
    case Emergency = 'emergency';
    case Elective = 'elective';
}
