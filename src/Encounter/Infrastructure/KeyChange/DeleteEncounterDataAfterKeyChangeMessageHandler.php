<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\KeyChange;

use App\Encounter\Application\Import\EncounterSchemaHandlerRegistry;
use App\Encounter\Application\Message\DeleteEncounterDataAfterKeyChangeMessage;
use App\Encounter\Domain\Entity\EncounterKeyState;
use App\Encounter\Infrastructure\Repository\EncounterImportRepository;
use App\Encounter\Infrastructure\Repository\EncounterKeyStateRepository;
use App\Import\Application\Contracts\ImportDeletionRefusedException;
use App\Import\Application\Service\ImportDeletionService;
use App\Import\Application\Service\ImportRelatedDataCleanupService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Shared\Infrastructure\Audit\AuditContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Second half of a key change with the option "delete" (ADR 027, #1050): deletes every encounter import
 * of the hospital uploaded with an earlier key generation through ImportDeletionService (visits with
 * their links and review decisions, rejects, report, source file), then ends the key change.
 *
 * Imports of schemas that attach records to encounters go first, those of schemas that create encounters
 * (`ed_visit`) last (ADR 037): records are assigned only within one key generation, so nothing is attached
 * any more when the creating imports are deleted. Should an import still be refused
 * (ImportDeletionRefusedException), the refusal came before anything was removed or rolled the removal
 * back, so the import stays complete with its old generation, as with the option "keep"; it is skipped and
 * logged, and the key change ends.
 *
 * An import that is still running is left for a retry: its run cannot write any more (generation check
 * in the commit) and ends as failed. Until every old import is gone the key change stays in progress and
 * uploads are refused. A retry or a repeated message is harmless; a message for a generation that is no
 * longer current ends nothing.
 *
 * @psalm-suppress UnusedClass Registered as a message handler.
 */
#[AsMessageHandler]
final readonly class DeleteEncounterDataAfterKeyChangeMessageHandler
{
    public const string COMPLETED_AUDIT_INTENT = 'encounter.key.change_completed';

    /** Wait before deleting a running import again; its run fails at the next generation check. */
    private const int RUNNING_IMPORT_RETRY_DELAY_MS = 60_000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EncounterImportRepository $encounterImports,
        private EncounterSchemaHandlerRegistry $handlers,
        private ManagerRegistry $managerRegistry,
        private EncounterKeyStateRepository $keyStates,
        private ImportDeletionService $deletionService,
        private ImportRelatedDataCleanupService $relatedDataCleanup,
        private AuditContext $auditContext,
        private ClockInterface $clock,
        #[Target('import')]
        private LoggerInterface $importLogger,
    ) {
    }

    public function __invoke(DeleteEncounterDataAfterKeyChangeMessage $message): void
    {
        $deleted = [];
        $running = 0;
        $refused = 0;
        $refreshOverview = false;

        foreach ($this->deletionOrder($this->encounterImports->importsBeforeGeneration($message->hospitalId, $message->keyGeneration)) as $importId) {
            $import = $this->entityManager->find(Import::class, $importId);
            if (!$import instanceof Import) {
                continue;
            }

            if (ImportStatus::RUNNING === $import->getStatus()) {
                ++$running;

                continue;
            }

            try {
                $refreshOverview = $this->deletionService->delete($import, refreshMaterializedView: false) || $refreshOverview;
                $deleted[] = $importId;
            } catch (ImportDeletionRefusedException $refusal) {
                ++$refused;
                $this->importLogger->warning('encounter.key_change.import_refused', [
                    'hospital_id' => $message->hospitalId,
                    'import_id' => $importId,
                    'msg' => $refusal->getMessage(),
                ]);
                // The removal rolled back; a failed transaction closes the entity manager.
                $this->managerRegistry->resetManager();
            }
        }

        if ($refreshOverview) {
            $this->relatedDataCleanup->refreshOverview(['import_ids' => $deleted]);
        }

        $this->importLogger->info('encounter.key_change.data_deleted', [
            'hospital_id' => $message->hospitalId,
            'generation' => $message->keyGeneration,
            'deleted' => \count($deleted),
            'running' => $running,
            'refused' => $refused,
        ]);

        if ($running > 0) {
            throw new RecoverableMessageHandlingException(sprintf('%d encounter import(s) of hospital #%d are still running; retrying the deletion.', $running, $message->hospitalId), retryDelay: self::RUNNING_IMPORT_RETRY_DELAY_MS, forceRetry: false);
        }

        $this->complete($message);
    }

    /**
     * Imports of attaching schemas first, then those of creating schemas, each ascending by id. A schema
     * without handler (bundle removed) counts as attaching.
     *
     * @param array<int, string> $imports schema by import id
     *
     * @return list<int>
     */
    private function deletionOrder(array $imports): array
    {
        $attaching = [];
        $creating = [];
        foreach ($imports as $importId => $schema) {
            if (true === $this->handlers->find($schema)?->createsEncounters()) {
                $creating[] = $importId;
            } else {
                $attaching[] = $importId;
            }
        }

        return [...$attaching, ...$creating];
    }

    private function complete(DeleteEncounterDataAfterKeyChangeMessage $message): void
    {
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($message): void {
            $this->keyStates->lockForImport($message->hospitalId, $this->clock->now());
            $state = $this->keyStates->findOneBy(['hospital' => $message->hospitalId]);
            if (!$state instanceof EncounterKeyState) {
                return;
            }
            $em->refresh($state);

            if (!$state->isKeyChangeInProgress() || $state->getKeyGeneration() !== $message->keyGeneration) {
                return;
            }

            $state->completeKeyChange();
            $this->auditContext->beginIntent(self::COMPLETED_AUDIT_INTENT, [
                'hospital_id' => $message->hospitalId,
                'generation' => $message->keyGeneration,
            ]);
            try {
                $em->flush();
            } finally {
                $this->auditContext->endIntent();
            }
        });
    }
}
