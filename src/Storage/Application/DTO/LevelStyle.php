<?php

declare(strict_types=1);

namespace App\Storage\Application\DTO;

use App\Storage\Domain\Enum\CapacityLevel;

/**
 * Bootstrap contextual style for a capacity level, the same on the card, the page and the meters.
 */
final class LevelStyle
{
    public static function for(CapacityLevel $level): string
    {
        return match ($level) {
            CapacityLevel::Normal => 'success',
            CapacityLevel::Notice => 'info',
            CapacityLevel::Warning => 'warning',
            CapacityLevel::Critical, CapacityLevel::Exceeded => 'danger',
            CapacityLevel::Unknown => 'secondary',
        };
    }
}
