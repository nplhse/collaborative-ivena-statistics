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
 * Sends the summary of a finished batch re-import to the user who started it (--notify-user). Runs of
 * a batch send no notification of their own. The publisher joins the caller's transaction.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for ImportBatchSummaryPublisherInterface.
 */
#[AsAlias(ImportBatchSummaryPublisherInterface::class)]
final readonly class ImportBatchSummaryNotifier implements ImportBatchSummaryPublisherInterface
{
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

        $this->publisher->publish(new NotificationRequest(
            type: 'import.batch_finished',
            recipientUserIds: [$summary->initiatedByUserId],
            deduplicationKey: \sprintf('import.batch_finished:%d', $summary->batchRunId),
            parameters: [
                'batchId' => $summary->batchRunId,
                'total' => $summary->total(),
                'completed' => $summary->completed,
                'partial' => $summary->partial,
                'failed' => $summary->failed,
                'superseded' => $summary->superseded,
            ],
            action: new NotificationAction('app_admin_dashboard_import_batch_run_detail', ['entityId' => $summary->batchRunId]),
            metadata: ['reference' => \sprintf('import_batch_run:%d', $summary->batchRunId)],
        ));
    }
}
