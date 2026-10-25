<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Notification;

use App\Import\Application\Contracts\ImportBulkDeletionPublisherInterface;
use App\Import\Application\DTO\ImportBulkDeletionSummary;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Sends the outcome of a background bulk deletion to the user who started it (#720). A deletion with
 * failures uses the high-priority type, so it is not overlooked. There is no durable record behind the
 * summary: a lost notification is not reconciled, the deletion itself is complete either way.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for ImportBulkDeletionPublisherInterface.
 */
#[AsAlias(ImportBulkDeletionPublisherInterface::class)]
final readonly class ImportBulkDeletionNotifier implements ImportBulkDeletionPublisherInterface
{
    public const string TYPE_DELETED = 'import.bulk_deleted';

    public const string TYPE_FAILED = 'import.bulk_delete_failed';

    public function __construct(
        private NotificationPublisherInterface $publisher,
    ) {
    }

    #[\Override]
    public function publish(ImportBulkDeletionSummary $summary): void
    {
        $type = $summary->failed > 0 ? self::TYPE_FAILED : self::TYPE_DELETED;

        $this->publisher->publish(new NotificationRequest(
            type: $type,
            recipientUserIds: [$summary->initiatedByUserId],
            deduplicationKey: \sprintf('%s:%s', $type, $summary->operationId),
            parameters: [
                'total' => $summary->total(),
                'deleted' => $summary->deleted,
                'skipped' => $summary->skipped,
                'failed' => $summary->failed,
            ],
            action: new NotificationAction('app_import_index'),
            metadata: ['reference' => \sprintf('import_bulk_delete:%s', $summary->operationId)],
        ));
    }
}
