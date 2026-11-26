<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * A participant requested a hospital assignment (#975, ADR 016 "Admin notifications"). Each administrator chooses in
 * the settings whether and on which channels to receive it; it links to the review page, which only shows the
 * request: approving needs the protected form there.
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
final class AdminHospitalAssignmentRequestedNotification extends AppNotification
{
    public const string TYPE = 'admin.hospital_assignment_requested';
}
