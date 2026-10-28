<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Synthetic data of a hospital is generated and all its projections are done (#791, ADR 023). Exists only where
 * the generator services are registered (dev, test, demo).
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: 'generation.completed',
    category: NotificationCategory::Imports,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
    accessScope: NotificationAccessScope::Hospital,
)]
final class GenerationCompletedNotification extends AppNotification
{
}
