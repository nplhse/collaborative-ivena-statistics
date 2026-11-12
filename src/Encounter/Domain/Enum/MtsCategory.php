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

    /**
     * Target time from the first assessment contact to the first physician contact, in minutes.
     */
    public function physicianContactTargetMinutes(): int
    {
        return match ($this) {
            self::Red => 0,
            self::Orange => 10,
            self::Yellow => 30,
            self::Green => 90,
            self::Blue => 120,
        };
    }
}
