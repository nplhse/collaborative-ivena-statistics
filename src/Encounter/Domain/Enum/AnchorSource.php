<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Timestamp used as time anchor of the visit fingerprint `ed-visit:v1` (ADR 028): the first contact of the initial assessment, or the ED admission when that is missing.
 */
enum AnchorSource: string
{
    case Assessment = 'assessment';
    case Admission = 'admission';
}
