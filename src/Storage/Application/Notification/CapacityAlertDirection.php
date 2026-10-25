<?php

declare(strict_types=1);

namespace App\Storage\Application\Notification;

/**
 * Which way the capacity level moved since the last storage alert (ADR 017, "Notifications").
 * The values are the select keys of the alert texts.
 */
enum CapacityAlertDirection: string
{
    case Escalated = 'escalated';
    case DeEscalated = 'deescalated';
    /** Fell back to normal. */
    case Resolved = 'resolved';
}
