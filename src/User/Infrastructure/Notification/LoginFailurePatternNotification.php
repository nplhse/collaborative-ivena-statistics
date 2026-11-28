<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * A day with a suspicious pattern of failed sign-ins (#1487): one username tried from many IPs, or one IP trying many
 * usernames. At most one per day; administrators can switch off the email.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: self::TYPE,
    category: NotificationCategory::Administration,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    accessScope: NotificationAccessScope::Role,
    // Longer than single failures are kept, so a day written again later is not announced twice.
    deduplicationTtl: 'P40D',
    requiredRole: 'ROLE_ADMIN',
)]
final class LoginFailurePatternNotification extends AppNotification
{
    public const string TYPE = 'admin.login_failure_pattern';
}
