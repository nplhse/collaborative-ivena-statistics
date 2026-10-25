<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * The daily storage report (ADR 017, "Notifications"). Opt-in: without default channels an administrator
 * only receives it after switching it on; the email is {@see StorageEmailCustomizer}.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: self::TYPE,
    category: NotificationCategory::Administration,
    defaultChannels: [],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    accessScope: NotificationAccessScope::Role,
    requiredRole: 'ROLE_ADMIN',
)]
final class StorageDailyReportNotification extends AppNotification
{
    public const string TYPE = 'storage.daily_report';
}
