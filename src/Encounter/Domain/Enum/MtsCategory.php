<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Manchester Triage System category, `mts_category` and `retriage_category` of the `ed_visit` exchange format (#1047).
 */
enum MtsCategory: string
{
    case Red = 'red';
    case Orange = 'orange';
    case Yellow = 'yellow';
    case Green = 'green';
    case Blue = 'blue';
}
