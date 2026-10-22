<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\EventSubscriber;

use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Event\ImportFailed;
use App\Import\Application\Event\ImportMilestoneReached;
use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Entity\UserImportMilestone;
use App\Import\Infrastructure\Notification\ImportMilestoneNotifier;
use App\Import\Infrastructure\Notification\ImportRunNotifier;
use App\Import\Infrastructure\Repository\ImportRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/**
 * Turns import outcomes and milestones into notifications (ADR 016, step 3b). Legacy runs without
 * run id are skipped; runs whose notification is lost are recovered by the reconciliation.
 */
final readonly class ImportNotificationSubscriber
{
    public function __construct(
        private ImportRunRepository $runRepository,
        private EntityManagerInterface $entityManager,
        private ImportRunNotifier $runNotifier,
        private ImportMilestoneNotifier $milestoneNotifier,
    ) {
    }

    #[AsEventListener(event: ImportCompleted::class)]
    public function onImportCompleted(ImportCompleted $event): void
    {
        $this->notifyRun($event->runId);
    }

    #[AsEventListener(event: ImportFailed::class)]
    public function onImportFailed(ImportFailed $event): void
    {
        $this->notifyRun($event->runId);
    }

    #[AsEventListener(event: ImportMilestoneReached::class)]
    public function onImportMilestoneReached(ImportMilestoneReached $event): void
    {
        $milestone = $this->entityManager->find(UserImportMilestone::class, $event->milestoneId);
        if ($milestone instanceof UserImportMilestone) {
            $this->milestoneNotifier->notify($milestone);
        }
    }

    private function notifyRun(?string $runId): void
    {
        if (null === $runId || !Uuid::isValid($runId)) {
            return;
        }

        $run = $this->runRepository->find(Uuid::fromString($runId));
        if ($run instanceof ImportRun) {
            // The terminal transition is a DQL update; a run in the identity map may still look open.
            $this->entityManager->refresh($run);
            $this->runNotifier->notify($run);
        }
    }
}
