<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * An approved hospital assignment request transferred the ownership of a hospital away from this user (#975); says
 * whether they kept full access.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: self::TYPE,
    category: NotificationCategory::Account,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast],
)]
final class HospitalOwnershipTransferredNotification extends AppNotification
{
    public const string TYPE = 'hospital_assignment.ownership_transferred';
}
