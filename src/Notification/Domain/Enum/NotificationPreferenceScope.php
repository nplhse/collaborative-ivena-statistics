<?php

declare(strict_types=1);

namespace App\Notification\Domain\Enum;

/**
 * Level of a stored preference override: a whole category or a single type (ADR 016).
 * A type override always beats a category override.
 */
enum NotificationPreferenceScope: string
{
    case Category = 'category';
    case Type = 'type';
}
