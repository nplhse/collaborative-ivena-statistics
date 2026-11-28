<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Sign-ins to the user's account were locked after too many wrong passwords (#1762, `AccountLock`). The email is
 * mandatory: the owner may not be signed in and must learn that someone is guessing the password.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: 'security.account_locked',
    category: NotificationCategory::Account,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    mandatoryChannels: [NotificationChannel::Email],
)]
final class AccountLockedNotification extends AppNotification
{
}
