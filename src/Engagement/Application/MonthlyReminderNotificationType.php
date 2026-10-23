<?php

declare(strict_types=1);

namespace App\Engagement\Application;

/**
 * Notification types of the monthly reminder (ADR 016). The definitions live in
 * Engagement\Infrastructure\Notification.
 */
final class MonthlyReminderNotificationType
{
    /** Scheduled reminder, configurable by the owner. */
    public const string SCHEDULED = 'engagement.monthly_reminder';

    /** Reminder an administrator sends by hand; email is mandatory. */
    public const string MANUAL = 'engagement.monthly_reminder_manual';
}
