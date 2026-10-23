<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\EventSubscriber;

use App\Import\Application\Event\ImportFailed;
use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Enum\ImportRunStatus;
use App\Import\Infrastructure\Notification\AdminImportFailedNotification;
use App\Import\Infrastructure\Repository\ImportRunRepository;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the administrators about a failed import run (`admin.import_failed`). Only runs that ended as
 * failed qualify, and only their reason code is passed on, never the raw error. Legacy runs without
 * run id are skipped, like their user notifications.
 */
#[AsEventListener(event: ImportFailed::class, method: 'onImportFailed')]
final readonly class ImportFailedNotificationSubscriber
{
    public function __construct(
        private ImportRunRepository $runRepository,
        private EntityManagerInterface $entityManager,
        private AdminNotificationRecipientResolverInterface $recipients,
        private NotificationPublisherInterface $publisher,
    ) {
    }

    public function onImportFailed(ImportFailed $event): void
    {
        if (null === $event->runId || !Uuid::isValid($event->runId)) {
            return;
        }

        $run = $this->runRepository->find(Uuid::fromString($event->runId));
        if (!$run instanceof ImportRun) {
            return;
        }

        // The terminal transition is a DQL update; a run in the identity map may still look open.
        $this->entityManager->refresh($run);
        if (ImportRunStatus::Failed !== $run->getStatus()) {
            return;
        }

        $import = $run->getImport();
        $importId = $import->getId();
        if (null === $importId) {
            return;
        }

        $recipientIds = $this->recipients->systemRecipientIds();
        if ([] === $recipientIds) {
            return;
        }

        $runId = $run->getId()->toRfc4122();
        $this->publisher->publish(new NotificationRequest(
            type: AdminImportFailedNotification::TYPE,
            recipientUserIds: $recipientIds,
            deduplicationKey: \sprintf('%s:%s', AdminImportFailedNotification::TYPE, $runId),
            parameters: [
                'importName' => $import->getName() ?? '',
                'hospitalName' => $import->getHospital()?->getName() ?? '',
                'rowsTotal' => $run->getRowsTotal(),
                'reason' => $run->getReasonCode()->value ?? 'other',
            ],
            action: new NotificationAction('app_import_show', ['id' => $importId]),
            metadata: ['reference' => \sprintf('import_run:%s', $runId)],
        ));
    }
}
