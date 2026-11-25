<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Release and export for the recipient (ADR 045): released, release ends within 14 days without an export, export
 * provided, download period ends within 24 hours. Parameter `kind`: released, release_expiring, provided,
 * download_expiring.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: self::TYPE,
    category: NotificationCategory::Research,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast],
    deduplicationTtl: 'P30D',
    translationDomain: 'research', position: 7,
)]
final class ExportNotification extends AppNotification
{
    public const string TYPE = 'research.export';
}
