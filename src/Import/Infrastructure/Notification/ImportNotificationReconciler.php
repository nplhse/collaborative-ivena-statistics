<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Notification;

use App\Import\Domain\Entity\UserImportMilestone;
use App\Import\Infrastructure\Repository\ImportRunRepository;
use App\Notification\Application\Contract\NotificationReconcilerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rebuilds import notifications from durable records: finished runs (import_run) and recorded
 * milestones (user_import_milestone). Milestones from the migration backfill have no run and are
 * never announced.
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

        return $published;
    }
}
