<?php

declare(strict_types=1);

namespace App\Import\Application\MessageHandler;

use App\Import\Application\Contracts\ImportBulkDeletionPublisherInterface;
use App\Import\Application\DTO\ImportBulkDeletionSummary;
use App\Import\Application\Message\DeleteImportsMessage;
use App\Import\Application\Service\ImportDeletionService;
use App\Import\Application\Service\ImportRelatedDataCleanupService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Security\Voter\ImportVoter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Deletes the imports of one bulk deletion, one after another (#720).
 *
 * Every import is checked again: it may be gone (counts as deleted), running (skipped) or no longer
 * deletable for the user, whom the audit middleware authenticates for the message. One failing import
 * does not stop the others. The overview materialized view is refreshed once at the end instead of per
 * import. The user who started the deletion gets one summary; its deduplication key makes a Messenger
 * retry harmless.
 */
#[AsMessageHandler]
final readonly class DeleteImportsMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
        private ImportDeletionService $deletionService,
        private ImportRelatedDataCleanupService $relatedDataCleanup,
        private AuthorizationCheckerInterface $authorizationChecker,
        private ImportBulkDeletionPublisherInterface $publisher,
        #[Target('import')]
        private LoggerInterface $importLogger,
    ) {
    }

    public function __invoke(DeleteImportsMessage $message): void
    {
        $deleted = [];
        $skipped = 0;
        $failed = 0;
        $refreshOverview = false;

        foreach ($message->importIds as $importId) {
            $import = $this->entityManager->find(Import::class, $importId);
            if (!$import instanceof Import) {
                // Deleted meanwhile, or by an earlier attempt of this message.
                $deleted[] = $importId;

                continue;
            }

            if (ImportStatus::RUNNING === $import->getStatus() || !$this->authorizationChecker->isGranted(ImportVoter::DELETE, $import)) {
                ++$skipped;
                $this->importLogger->info('import.bulk_delete.skipped', [
                    'operation_id' => $message->operationId,
                    'id' => $importId,
                    'status' => $import->getStatus()?->value,
                ]);

                continue;
            }

            try {
                $refreshOverview = $this->deletionService->delete($import, refreshMaterializedView: false) || $refreshOverview;
                $deleted[] = $importId;
            } catch (\Throwable $e) {
                ++$failed;
                $this->importLogger->error('import.bulk_delete.failed', [
                    'operation_id' => $message->operationId,
                    'id' => $importId,
                    'ex' => $e::class,
                    'msg' => $e->getMessage(),
                ]);
                // A failed flush closes the entity manager; resetting reopens the injected one for the next import.
                $this->managerRegistry->resetManager();
            }
        }

        if ($refreshOverview) {
            $this->relatedDataCleanup->refreshOverview(['import_ids' => $deleted]);
        }

        $this->importLogger->info('import.bulk_delete.finished', [
            'operation_id' => $message->operationId,
            'deleted' => \count($deleted),
            'skipped' => $skipped,
            'failed' => $failed,
        ]);

        $this->publisher->publish(new ImportBulkDeletionSummary(
            operationId: $message->operationId,
            initiatedByUserId: $message->initiatedByUserId,
            deleted: \count($deleted),
            skipped: $skipped,
            failed: $failed,
        ));
    }
}
