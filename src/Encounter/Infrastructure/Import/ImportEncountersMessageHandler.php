<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Event\EncountersImported;
use App\Encounter\Application\Import\EncounterFileRejectedException;
use App\Encounter\Application\Import\EncounterImportResult;
use App\Encounter\Application\Import\EncounterKeyGenerationChangedException;
use App\Encounter\Application\Message\ImportEncountersMessage;
use App\Encounter\Domain\Entity\EncounterImport;
use App\Encounter\Domain\Entity\EncounterSuspectedDuplicate;
use App\Encounter\Domain\Enum\EncounterFileRejection;
use App\Encounter\Infrastructure\Repository\EncounterImportRepository;
use App\Encounter\Infrastructure\Repository\EncounterKeyStateRepository;
use App\Encounter\Infrastructure\Security\EncounterUploadPermission;
use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\DTO\ImportRunOutcome;
use App\Import\Application\DTO\ImportSummary;
use App\Import\Application\Exception\ImportFilePathOutsideBaseException;
use App\Import\Application\Exception\ImportRunSupersededException;
use App\Import\Application\Service\ImportFileStorage;
use App\Import\Application\Service\ImportPreviousRunCleanupService;
use App\Import\Application\Service\ImportRunCommitGuard;
use App\Import\Application\Service\ImportRunErrorClassifier;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportReject;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportType;
use App\Import\Domain\Service\ImportEvaluation;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Runs an encounter import (#1048) with the import run lifecycle of the other import types (ADR 016):
 * claim the run, check preconditions, remove the data of a previous run, process the file, end the run.
 *
 * - The creator must still hold the beta role and the UPLOAD grant (or ROLE_ADMIN) on the hospital.
 * - The key generation stored at upload is checked before anything is removed and again in the commit
 *   that writes the visits (ADR 026); a mismatch fails the run without writing.
 * - A rejected file fails the run with `invalid_file`; the reason is stored on the encounter import.
 *   A raw HIS export or a file with identifying content is deleted at once (ADR 025).
 * - After a successful run with new visits, EncountersImported names the affected hospital-days.
 *
 * @psalm-suppress UnusedClass Registered as a message handler.
 */
#[AsMessageHandler]
final readonly class ImportEncountersMessageHandler
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private ImportRunLifecycle $runLifecycle,
        private ImportRunCommitGuard $commitGuard,
        private ImportPreviousRunCleanupService $previousRunCleanup,
        private ImportFileStorage $fileStorage,
        private EncounterImportRepository $encounterImports,
        private EncounterKeyStateRepository $keyStates,
        private EncounterUploadPermission $uploadPermission,
        private EncounterFileImporter $fileImporter,
        private AuditContext $auditContext,
        private EventDispatcherInterface $eventDispatcher,
        #[Target('import')]
        private LoggerInterface $importLogger,
    ) {
    }

    public function __invoke(ImportEncountersMessage $message): void
    {
        $context = $this->runLifecycle->start($message);
        if (!$context instanceof ImportRunContext) {
            return;
        }

        $this->processRun($context);
    }

    private function processRun(ImportRunContext $context): void
    {
        $em = $this->entityManager();
        $import = $em->find(Import::class, $context->importId);
        if (!$import instanceof Import) {
            $this->importLogger->error('encounter.import.not_found', ['id' => $context->importId]);

            return;
        }

        $encounterImport = ImportType::ENCOUNTER === $import->getType() ? $this->encounterImports->findForImport($import) : null;
        $source = $this->resolveSource($import, $encounterImport);
        if ($source instanceof ImportRunReasonCode) {
            $this->runLifecycle->fail($context, $source);

            return;
        }

        \assert($encounterImport instanceof EncounterImport);
        if ($this->keyGenerationChanged($import, $encounterImport)) {
            $this->failKeyGenerationChanged($context, 0);

            return;
        }

        $started = microtime(true);
        $this->auditContext->pushSuppressedEntityAudit([ImportReject::class, EncounterSuspectedDuplicate::class]);
        $this->commitGuard->activate($context);
        try {
            if ($import->hasRunBefore() || $context->resumed) {
                $this->previousRunCleanup->cleanup($import);
            }

            $import->markAsRunning();
            $encounterImport->resetForRun();
            $this->flushWithIntent('import.run.started', $import);

            $result = $this->fileImporter->import($import, $encounterImport, $source);
            $runtimeMs = $this->runtimeSince($started);

            $outcome = $this->runLifecycle->finish(
                $context,
                fn (Import $fresh): ImportRunOutcome => $this->applyResult($fresh, $result, $runtimeMs),
            );

            if (true === $outcome?->status->isSuccessful() && [] !== $result->insertedDays) {
                $this->eventDispatcher->dispatch(new EncountersImported((int) $import->getHospital()?->getId(), $context->importId, $result->insertedDays));
            }
        } catch (EncounterFileRejectedException $e) {
            $this->rejectFile($context, $import, $e->reason, $this->runtimeSince($started));
        } catch (EncounterKeyGenerationChangedException $e) {
            $this->importLogger->warning('encounter.import.key_generation_changed', ['id' => $context->importId, 'msg' => $e->getMessage()]);
            $this->failKeyGenerationChanged($context, $this->runtimeSince($started));
        } catch (ImportRunSupersededException) {
            $this->importLogger->info('encounter.import.run.superseded_during_processing', [
                'id' => $context->importId,
                'run_id' => $context->runId->toRfc4122(),
            ]);
            $this->entityManager()->clear();
        } catch (\Throwable $e) {
            $this->handleRunError($context, $e, $this->runtimeSince($started));
        } finally {
            $this->commitGuard->deactivate();
            $this->auditContext->popSuppressedEntityAudit();
        }
    }

    private function applyResult(Import $fresh, EncounterImportResult $result, int $runtimeMs): ImportRunOutcome
    {
        $this->encounterImportOf($fresh)->recordCounts($result->inserted, $result->duplicates, $result->skipped, $result->rejected, $result->suspectedDuplicates);
        $fresh->applyDataCoverage($result->coverage);
        ImportEvaluation::apply($fresh, new ImportSummary($result->total, $result->total - $result->rejected, $result->rejected), $runtimeMs);
        $fresh->setRowsDeduplicated($result->duplicates);

        return ImportRunOutcome::fromEvaluatedImport($fresh);
    }

    /**
     * Ends the run as failed with `invalid_file` and stores the reason on the encounter import. A file
     * that may hold direct identifiers is deleted first (ADR 025).
     */
    private function rejectFile(ImportRunContext $context, Import $import, EncounterFileRejection $reason, int $runtimeMs): void
    {
        $deleted = false;
        if ($reason->deletesSourceFile()) {
            $this->fileStorage->delete($import->getFilePath(), 'encounter.import.source_file.deleted', $context->importId);
            $deleted = !$this->fileStorage->exists($import->getFilePath());
        }

        $this->importLogger->warning('encounter.import.file_rejected', [
            'id' => $context->importId,
            'reason' => $reason->value,
            'source_file_deleted' => $deleted,
        ]);

        $this->entityManager()->clear();
        $this->runLifecycle->fail(
            $context,
            ImportRunReasonCode::InvalidFile,
            function (Import $fresh) use ($reason, $deleted, $runtimeMs): void {
                $fresh->markAsFailed($runtimeMs);
                $this->encounterImportOf($fresh)->rejectFile($reason, $deleted);
            },
        );
    }

    private function failKeyGenerationChanged(ImportRunContext $context, int $runtimeMs): void
    {
        $this->entityManager()->clear();
        $this->runLifecycle->fail(
            $context,
            ImportRunReasonCode::KeyGenerationChanged,
            static function (Import $fresh) use ($runtimeMs): void {
                $fresh->markAsFailed($runtimeMs);
            },
        );
    }

    private function handleRunError(ImportRunContext $context, \Throwable $e, int $runtimeMs): void
    {
        $logContext = [
            'id' => $context->importId,
            'run_id' => $context->runId->toRfc4122(),
            'ex' => $e::class,
            'msg' => $e->getMessage(),
        ];

        if (ImportRunErrorClassifier::isRetryable($e)) {
            $this->importLogger->warning('encounter.import.run.retryable_error', $logContext);

            throw $e;
        }

        $this->importLogger->critical('encounter.import.failed', $logContext);
        $this->entityManager()->clear();
        $this->runLifecycle->fail(
            $context,
            ImportRunErrorClassifier::reasonCodeFor($e),
            static function (Import $fresh) use ($runtimeMs): void {
                $fresh->markAsFailed($runtimeMs);
            },
        );
    }

    /**
     * @return string|ImportRunReasonCode the absolute path of the source file, or why the run cannot start
     */
    private function resolveSource(Import $import, ?EncounterImport $encounterImport): string|ImportRunReasonCode
    {
        if (!$encounterImport instanceof EncounterImport) {
            return ImportRunReasonCode::TypeMismatch;
        }

        $creator = $import->getCreatedBy();
        if (!$creator instanceof User) {
            return ImportRunReasonCode::MissingCreator;
        }

        $hospital = $import->getHospital();
        if (!$hospital instanceof Hospital) {
            return ImportRunReasonCode::MissingHospital;
        }

        if (!$this->uploadPermission->allows($creator, $hospital)) {
            return ImportRunReasonCode::MissingImportPermission;
        }

        $filePath = $import->getFilePath();
        if (null === $filePath || $encounterImport->isSourceFileDeleted()) {
            return ImportRunReasonCode::FileNotFound;
        }

        try {
            $absolute = $this->fileStorage->resolve($filePath);
        } catch (ImportFilePathOutsideBaseException) {
            return ImportRunReasonCode::InvalidFilePath;
        }

        return is_file($absolute) ? $absolute : ImportRunReasonCode::FileNotFound;
    }

    /**
     * Checked before the data of a previous run is removed, so a reprocess after a key change keeps it.
     */
    private function keyGenerationChanged(Import $import, EncounterImport $encounterImport): bool
    {
        $state = $this->keyStates->currentState((int) $import->getHospital()?->getId());

        return $state['generation'] !== $encounterImport->getKeyGeneration() || $state['key_change_in_progress'];
    }

    private function encounterImportOf(Import $import): EncounterImport
    {
        return $this->encounterImports->findForImport($import)
            ?? throw new \LogicException(sprintf('Encounter import #%d has no encounter state.', (int) $import->getId()));
    }

    private function flushWithIntent(string $intent, Import $import): void
    {
        $this->auditContext->beginIntent($intent, ['import_id' => $import->getId()]);
        try {
            $this->commitGuard->flush($this->entityManager());
        } finally {
            $this->auditContext->endIntent();
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->managerRegistry->getManager();
        if ($em instanceof EntityManagerInterface && !$em->isOpen()) {
            $this->managerRegistry->resetManager();
            $em = $this->managerRegistry->getManager();
        }

        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('Expected Doctrine ORM EntityManager.');
        }

        return $em;
    }

    private function runtimeSince(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000.0);
    }
}
