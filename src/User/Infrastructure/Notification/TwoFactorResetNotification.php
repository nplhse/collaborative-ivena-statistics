<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Two-factor authentication of the user's account was reset by an administrator or an operator (#1357, ADR 040).
 * The email is mandatory: someone who did not ask for the reset must learn about it.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: 'security.two_factor_reset',
    category: NotificationCategory::Account,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    mandatoryChannels: [NotificationChannel::Email],
)]
final class TwoFactorResetNotification extends AppNotification
{
}
