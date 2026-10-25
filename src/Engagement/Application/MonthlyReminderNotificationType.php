<?php

declare(strict_types=1);

namespace App\Engagement\Application;

/**
 * Notification type of the monthly reminder (ADR 016), used for scheduled, CLI and admin sends alike.
 * The definition lives in Engagement\Infrastructure\Notification.
 */
final class MonthlyReminderNotificationType
{
    public const string TYPE = 'engagement.monthly_reminder';
}
