<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Notification;

use App\Import\Application\Contracts\ImportBatchSummaryPublisherInterface;
use App\Import\Application\Service\ImportBatchCompletion;
use App\Import\Domain\Entity\UserImportMilestone;
use App\Import\Infrastructure\Repository\ImportBatchRunRepository;
use App\Import\Infrastructure\Repository\ImportRunRepository;
use App\Notification\Application\Contract\NotificationReconcilerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rebuilds import notifications from durable records: finished runs (import_run), recorded
 * milestones (user_import_milestone) and finished batches (import_batch_run). Milestones from the
 * migration backfill have no run and are never announced. Batches whose last item finished without
 * completing the batch (e.g. a crash after closing the dispatch phase) are completed here.
 *
 * @psalm-suppress UnusedClass Tagged via NotificationReconcilerInterface.
 */
final readonly class ImportNotificationReconciler implements NotificationReconcilerInterface
{
    public function __construct(
        private ImportRunRepository $runRepository,
        private EntityManagerInterface $entityManager,
        private ImportRunNotifier $runNotifier,
        private ImportMilestoneNotifier $milestoneNotifier,
        private ImportBatchRunRepository $batchRunRepository,
        private ImportBatchCompletion $batchCompletion,
        private ImportBatchSummaryPublisherInterface $batchSummaryPublisher,
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function reconcile(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $published = 0;

        foreach ($this->runRepository->findFinishedBetween($from, $to) as $run) {
            $this->runNotifier->notify($run);
            ++$published;
        }

        /** @var list<UserImportMilestone> $milestones */
        $milestones = $this->entityManager->createQuery(\sprintf(
            'SELECT milestone FROM %s milestone WHERE milestone.importRun IS NOT NULL AND milestone.achievedAt BETWEEN :from AND :to ORDER BY milestone.achievedAt ASC',
            UserImportMilestone::class,
        ))
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getResult();

        foreach ($milestones as $milestone) {
            $this->milestoneNotifier->notify($milestone);
            ++$published;
        }

        foreach ($this->batchRunRepository->findIdsAwaitingCompletion() as $batchRunId) {
            if ($this->batchCompletion->complete($batchRunId)) {
                ++$published;
            }
        }

        foreach ($this->batchRunRepository->findIdsFinishedProcessingBetween($from, $to) as $batchRunId) {
            $this->batchSummaryPublisher->publish($this->batchCompletion->summarize($this->connection, $batchRunId));
            ++$published;
        }

        return $published;
    }
}
