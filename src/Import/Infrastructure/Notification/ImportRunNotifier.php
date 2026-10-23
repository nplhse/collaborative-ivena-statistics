<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Notification;

use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Enum\ImportRunStatus;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;

/**
 * Publishes the outcome of one finished import run (ADR 016, "Import runs").
 *
 * Recipients follow the run's trigger: an upload notifies the import's creator and, if different, the
 * hospital owner; a console run notifies only its initiator (nobody without --notify-user); runs of a
 * requeue batch get one summary per batch instead (step 4). The deduplication key is per run, so the
 * event subscriber and the reconciliation can both call this safely.
 */
final readonly class ImportRunNotifier
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private NotificationPublisherInterface $publisher,
    ) {
    }

    public function notify(ImportRun $run): void
    {
        $status = $run->getStatus();
        if (!$status->isSuccessful() && ImportRunStatus::Failed !== $status) {
            return;
        }

        $recipients = $this->recipients($run);
        if ([] === $recipients) {
            return;
        }

        $import = $run->getImport();
        $importId = $import->getId();
        $hospitalId = $import->getHospital()?->getId();
        if (null === $importId || null === $hospitalId) {
            return;
        }

        $this->publisher->publish(new NotificationRequest(
            type: $status->isSuccessful() ? 'import.completed' : 'import.failed',
            recipientUserIds: $recipients,
            deduplicationKey: \sprintf('import.run_finished:%s', $run->getId()->toRfc4122()),
            parameters: [
                'importName' => $import->getName() ?? '',
                'hospitalName' => $import->getHospital()?->getName() ?? '',
                'rowsTotal' => $run->getRowsTotal() ?? 0,
                'rowsOk' => $run->getRowsOk() ?? 0,
                'rowsRejected' => $run->getRowsRejected() ?? 0,
                'reason' => $run->getReasonCode()->value ?? 'other',
            ],
            action: new NotificationAction('app_import_show', ['id' => $importId]),
            scopeReferenceId: $hospitalId,
            metadata: ['reference' => \sprintf('import_run:%s', $run->getId()->toRfc4122())],
        ));
    }

    /**
     * @return list<int>
     */
    private function recipients(ImportRun $run): array
    {
        $ids = match ($run->getTrigger()) {
            ImportRunTrigger::Upload => [
                $run->getImport()->getCreatedBy()?->getId(),
                $run->getImport()->getHospital()?->getOwner()?->getId(),
            ],
            ImportRunTrigger::Console => [$run->getInitiatedBy()?->getId()],
            ImportRunTrigger::BatchRequeue => [],
        };

        return array_values(array_unique(array_filter($ids, static fn (?int $id): bool => null !== $id)));
    }
}
