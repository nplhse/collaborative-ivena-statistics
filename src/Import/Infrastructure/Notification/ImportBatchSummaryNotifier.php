<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Notification;

use App\Import\Application\Contracts\ImportBatchSummaryPublisherInterface;
use App\Import\Application\DTO\ImportBatchSummary;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Sends the summary of a finished batch (console re-import or bulk reprocess from the import list) to the
 * user who started it. Runs of a batch send no notification of their own. A batch with failures uses the
 * high-priority type `import.batch_failed`, so the failures stay visible. The publisher joins the caller's
 * transaction.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for ImportBatchSummaryPublisherInterface.
 */
#[AsAlias(ImportBatchSummaryPublisherInterface::class)]
final readonly class ImportBatchSummaryNotifier implements ImportBatchSummaryPublisherInterface
{
    public const string TYPE_FINISHED = 'import.batch_finished';

    public const string TYPE_FAILED = 'import.batch_failed';

    public function __construct(
        private NotificationPublisherInterface $publisher,
    ) {
    }

    #[\Override]
    public function publish(ImportBatchSummary $summary): void
    {
        if (null === $summary->initiatedByUserId) {
            return;
        }

        $type = $summary->failed > 0 ? self::TYPE_FAILED : self::TYPE_FINISHED;

        $this->publisher->publish(new NotificationRequest(
            type: $type,
            recipientUserIds: [$summary->initiatedByUserId],
            deduplicationKey: \sprintf('%s:%d', $type, $summary->batchRunId),
            parameters: [
                'batchId' => $summary->batchRunId,
                'total' => $summary->total(),
                'succeeded' => $summary->completed + $summary->partial,
                'completed' => $summary->completed,
                'partial' => $summary->partial,
                'failed' => $summary->failed,
                'superseded' => $summary->superseded,
            ],
            // The import list filtered to the batch shows every import and its status, also to non-admins.
            action: new NotificationAction('app_import_index', ['batch' => $summary->batchRunId]),
            metadata: ['reference' => \sprintf('import_batch_run:%d', $summary->batchRunId)],
        ));
    }
}
