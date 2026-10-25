<?php

declare(strict_types=1);

namespace App\Notification\Domain\Enum;

/**
 * User-facing delivery channels. Toast is not a transport of its own; it is shown for an in-app entry.
 */
enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
    case Toast = 'toast';
}
