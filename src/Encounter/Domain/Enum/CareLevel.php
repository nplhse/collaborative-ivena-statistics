<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Care level of an admission or external transfer, `care_level` of the `ed_visit` exchange format (#1047).
 */
enum CareLevel: string
{
    case Ward = 'ward';
    case Monitoring = 'monitoring';
    case Icu = 'icu';
    case OperatingRoom = 'or';
    case Unknown = 'unknown';
}
