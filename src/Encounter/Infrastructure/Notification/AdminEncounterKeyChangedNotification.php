<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * The pseudonym key of a hospital's encounter data was changed (ADR 026, ADR 016 "Admin notifications").
 * Goes to the administrators who receive it, once per key generation; it stores the hospital name, the
 * username of the person who changed it, the new generation and the chosen option.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: self::TYPE,
    category: NotificationCategory::Administration,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast],
    accessScope: NotificationAccessScope::Role,
    requiredRole: 'ROLE_ADMIN',
)]
final class AdminEncounterKeyChangedNotification extends AppNotification
{
    public const string TYPE = 'admin.encounter_key_changed';
}
