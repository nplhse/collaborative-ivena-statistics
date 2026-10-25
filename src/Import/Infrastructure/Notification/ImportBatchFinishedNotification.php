<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * All imports of a batch are done without failures (ADR 016): a console re-import or a bulk reprocess
 * from the import list (#720). Links to the import list filtered by the batch, so every user may see it.
 * A batch with failures sends `import.batch_failed` instead.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: 'import.batch_finished',
    category: NotificationCategory::Imports,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast],
)]
final class ImportBatchFinishedNotification extends AppNotification
{
}
