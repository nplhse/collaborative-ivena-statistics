<?php

declare(strict_types=1);

namespace App\Notification\Application\Inbox;

enum NotificationVisibility
{
    case Visible;

    /** Shown with a generic text, without names, counts or action link. */
    case Redacted;

    /** Not shown at all. */
    case Hidden;
}
