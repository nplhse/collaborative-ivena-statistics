<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Messenger;

use App\Research\Application\DataRequest\DataRequestReleaseService;
use App\Research\Application\Message\RunDataExportMessage;
use App\Research\Domain\Entity\DataRequestExportRun;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the export of a release in the worker (ADR 045). Idempotent: a run that is no longer queued, or that another
 * worker took over first, is left alone, so a redelivered message never starts a second run. Errors end in a failed
 * run instead of a Messenger retry.
 *
 * @psalm-suppress UnusedClass Registered via #[AsMessageHandler].
 */
#[AsMessageHandler]
final readonly class RunDataExportMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DataRequestReleaseService $releases,
    ) {
    }

    public function __invoke(RunDataExportMessage $message): void
    {
        $run = $this->entityManager->find(DataRequestExportRun::class, $message->runId);
        if (!$run instanceof DataRequestExportRun || !$this->releases->claim($run)) {
            return;
        }

        $this->releases->execute($run);
    }
}
