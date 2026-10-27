<?php

declare(strict_types=1);

namespace App\Import\Application\MessageHandler;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Import\Application\Audit\ImportRunSuppressedAuditClasses;
use App\Import\Application\Contracts\ImportRunTelemetryInterface;
use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\DTO\ImportRunOutcome;
use App\Import\Application\DTO\ImportSummary;
use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Event\ImportFailed;
use App\Import\Application\Exception\ImportFilePathOutsideBaseException;
use App\Import\Application\Exception\ImportRunSupersededException;
use App\Import\Application\Factory\ClosureImporterFactory;
use App\Import\Application\Factory\RejectWriterFactory;
use App\Import\Application\Factory\RowReaderFactory;
use App\Import\Application\Mapping\ClosureHospitalGuard;
use App\Import\Application\Mapping\ClosureHospitalProfile;
use App\Import\Application\Message\ImportClosuresMessage;
use App\Import\Application\Service\ImportCreatorPermissionCheck;
use App\Import\Application\Service\ImportDataCoverageCalculator;
use App\Import\Application\Service\ImportExportPeriodDeriver;
use App\Import\Application\Service\ImportFileStorage;
use App\Import\Application\Service\ImportPreviousRunCleanupService;
use App\Import\Application\Service\ImportRunCommitGuard;
use App\Import\Application\Service\ImportRunErrorClassifier;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportRunStatus;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Domain\Service\ImportEvaluation;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\Statistics\Application\Contract\ClosureRebuildRequestInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsMessageHandler]
final readonly class ImportClosuresMessageHandler
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private ImportRepository $importRepository,
        private EntityManagerInterface $em,
        private ClosureImporterFactory $importFactory,
        private RowReaderFactory $rowReaderFactory,
        private RejectWriterFactory $rejectWriterFactory,
        private LoggerInterface $importLogger,
        private EventDispatcherInterface $dispatcher,
        private ImportPreviousRunCleanupService $previousRunCleanupService,
        private ImportCreatorPermissionCheck $creatorPermissionCheck,
        private AuditContext $auditContext,
        private ManagerRegistry $managerRegistry,
        private ImportFileStorage $fileStorage,
        private HospitalRepository $hospitalRepository,
        private ClosureHospitalGuard $hospitalGuard,
        private ClosureRebuildRequestInterface $closureRebuildScheduler,
        private ImportRunLifecycle $runLifecycle,
        private ImportRunCommitGuard $commitGuard,
        private ImportExportPeriodDeriver $exportPeriodDeriver,
        private ImportDataCoverageCalculator $dataCoverageCalculator,
        private ImportRunTelemetryInterface $telemetry,
    ) {
    }

    public function __invoke(ImportClosuresMessage $message): void
    {
        if (null === $message->runId) {
            $this->importLogger->warning('notification.import_run.legacy_message', [
                'id' => $message->importId,
                'message' => ImportClosuresMessage::class,
            ]);
            $this->processLegacyMessage($message->importId);

            return;
        }

        $context = $this->runLifecycle->start($message);
        if (!$context instanceof ImportRunContext) {
            return;
        }

        $this->processRun($context);
    }

    public function run(Import $import, RowReaderInterface $reader, RejectWriterInterface $writer, ClosureHospitalProfile $profile): ImportSummary
    {
        $started = \microtime(true);
        $summary = ImportSummary::empty();

        try {
            $importer = $this->importFactory->create($reader, $writer);
            $summary = $importer->import($import, $profile);

            $this->entityManager()->clear();
            $fresh = $this->importRepository->find($import->getId());
            if (!$fresh instanceof Import) {
                throw new \RuntimeException('Import not found after refresh');
            }

            $this->applyResult($fresh, $summary, $writer, $this->runtimeSince($started));
            $this->flushWithImportIntent('import.run.finished', $fresh);

            return $summary;
        } catch (\Throwable $e) {
            $runtimeMs = $this->runtimeSince($started);
            $importId = $import->getId();
            if (null !== $importId) {
                $this->entityManager();
                $import = $this->importRepository->find($importId) ?? $import;
            }

            $import->markAsFailed(
                $runtimeMs,
                $summary->total,
                $summary->ok,
                $summary->rejected,
            );
            $this->flushWithImportIntent('import.run.failed', $import, ['reason' => $e->getMessage()]);

            $this->importLogger->error('closure.import.failed.precondition', [
                'id' => $import->getId(),
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Processes one run. Non-retryable errors end the run as failed; retryable errors are rethrown
     * so Messenger retries the message with the same run.
     */
    private function processRun(ImportRunContext $context): void
    {
        $import = $this->importRepository->find($context->importId);
        if (!$import instanceof Import) {
            $this->importLogger->error('closure.import.not_found', ['id' => $context->importId]);

            return;
        }

        $this->telemetry->begin($context, $import);
        $source = $this->resolveSourceFile($import);
        if ($source instanceof ImportRunReasonCode) {
            $failed = $this->runLifecycle->fail($context, $source);
            $this->telemetry->end($failed ? ImportRunStatus::Failed->value : ImportRunStatus::Superseded->value);

            return;
        }

        $hospitalId = $import->getHospital()?->getId();
        \assert(\is_int($hospitalId));

        $started = \microtime(true);
        $cleanedPreviousRun = false;
        $status = ImportRunStatus::Failed->value;
        $error = null;
        $this->auditContext->pushSuppressedEntityAudit(ImportRunSuppressedAuditClasses::fqcnList());
        $this->commitGuard->activate($context);
        try {
            // A resumed run may have committed chunks before it failed; remove them like a previous run's data.
            // The cleanup is a guarded commit, so it never deletes data of a newer run.
            if ($import->hasRunBefore() || $context->resumed) {
                $this->telemetry->startSpan('import.cleanup');
                $this->previousRunCleanupService->cleanup($import);
                $this->telemetry->finishSpan();
                $cleanedPreviousRun = true;
            }

            $import->markAsRunning();
            $this->flushWithImportIntent('import.run.started', $import);

            $writer = $this->telemetry->timedRejectWriter($this->rejectWriterFactory->create());
            $writer->start($import);

            $this->telemetry->startSpan('import.profile', ['hospital_id' => $hospitalId]);
            $profile = $this->hospitalProfile($hospitalId, $source);
            $this->telemetry->finishSpan();
            $reader = $this->telemetry->timedReader($this->rowReaderFactory->createFromCsvFile($source));
            $this->telemetry->startRows();
            $summary = $this->importFactory->create($reader, $writer)->import($import, $profile);
            $this->telemetry->rowsImported($summary);
            $runtimeMs = $this->runtimeSince($started);

            $this->telemetry->startSpan('import.finish');
            $outcome = $this->runLifecycle->finish(
                $context,
                function (Import $fresh) use ($summary, $writer, $runtimeMs): ImportRunOutcome {
                    $this->applyResult($fresh, $summary, $writer, $runtimeMs);
                    $this->telemetry->evaluated($fresh);

                    return ImportRunOutcome::fromEvaluatedImport($fresh);
                },
            );
            $this->telemetry->finishSpan();
            $status = $outcome instanceof ImportRunOutcome ? $outcome->status->value : ImportRunStatus::Superseded->value;
        } catch (ImportRunSupersededException) {
            $status = ImportRunStatus::Superseded->value;
            $this->importLogger->info('closure.import.run.superseded_during_processing', [
                'id' => $context->importId,
                'run_id' => $context->runId->toRfc4122(),
            ]);
            $this->entityManager()->clear();
        } catch (\Throwable $e) {
            $error = $e;
            $status = ImportRunErrorClassifier::isRetryable($e) ? ImportRunTelemetryInterface::STATUS_RETRYING : ImportRunStatus::Failed->value;
            if ($cleanedPreviousRun) {
                $this->closureRebuildScheduler->requestAnalysis($hospitalId);
            }

            $this->handleRunError($context, $e, $this->runtimeSince($started));
        } finally {
            $this->telemetry->end($status, $error);
            $this->commitGuard->deactivate();
            $this->auditContext->popSuppressedEntityAudit();
        }
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
            $this->importLogger->warning('closure.import.run.retryable_error', $logContext);

            throw $e;
        }

        $this->importLogger->critical('closure.import.failed', $logContext);

        $this->runLifecycle->fail(
            $context,
            ImportRunErrorClassifier::reasonCodeFor($e),
            static function (Import $import) use ($runtimeMs): void {
                $import->markAsFailed($runtimeMs);
            },
        );
    }

    private function applyResult(Import $fresh, ImportSummary $summary, RejectWriterInterface $writer, int $runtimeMs): void
    {
        $absRejectPath = $writer->getPath();
        if ($summary->rejected > 0 && \is_string($absRejectPath) && '' !== $absRejectPath) {
            $fresh->setRejectFilePath($this->fileStorage->toRelative($absRejectPath));
        }

        $this->exportPeriodDeriver->apply($fresh);
        $this->dataCoverageCalculator->apply($fresh);

        ImportEvaluation::apply($fresh, $summary, $runtimeMs);
    }

    /**
     * Behaviour for messages queued before import runs existed. Remove once no legacy messages remain.
     */
    private function processLegacyMessage(int $importId): void
    {
        $import = $this->importRepository->findOneBy(['id' => $importId]);
        if (!$import instanceof Import) {
            $this->importLogger->error('closure.import.not_found', ['id' => $importId]);

            return;
        }

        $source = $this->resolveSourceFile($import);
        if ($source instanceof ImportRunReasonCode) {
            $reason = $source->describe();
            $this->markFailed($import, $reason);
            $this->dispatchImportOutcome($importId, $reason);

            return;
        }

        $filePath = $source;

        $this->auditContext->pushSuppressedEntityAudit(ImportRunSuppressedAuditClasses::fqcnList());
        try {
            $cleanedPreviousRun = false;
            if ($import->hasRunBefore()) {
                $this->previousRunCleanupService->cleanup($import);
                $cleanedPreviousRun = true;
            }

            $import->markAsRunning();
            $this->flushWithImportIntent('import.run.started', $import);

            $hospitalId = $import->getHospital()?->getId();
            \assert(\is_int($hospitalId));

            $writer = $this->rejectWriterFactory->create();
            $writer->start($import);

            $startedRun = false;
            try {
                $profile = $this->hospitalProfile($hospitalId, $filePath);
                $reader = $this->rowReaderFactory->createFromCsvFile($filePath);
                $startedRun = true;
                $this->run($import, $reader, $writer, $profile);
            } catch (\Throwable $e) {
                if ($cleanedPreviousRun) {
                    $this->closureRebuildScheduler->requestAnalysis($hospitalId);
                }
                if (!$startedRun) {
                    $this->markFailed($import, $e->getMessage());
                }

                $this->importLogger->critical('closure.import.failed', [
                    'id' => $import->getId(),
                    'ex' => $e::class,
                    'msg' => $e->getMessage(),
                ]);
                $this->dispatchImportOutcome($importId, $e->getMessage());

                return;
            }

            $this->dispatchImportOutcome($importId);
        } finally {
            $this->auditContext->popSuppressedEntityAudit();
        }
    }

    /**
     * @return string|ImportRunReasonCode the resolved absolute path, or why the run cannot start
     */
    private function resolveSourceFile(Import $import): string|ImportRunReasonCode
    {
        $permissionFailure = $this->resolvePermissionFailure($import);
        if ($permissionFailure instanceof ImportRunReasonCode) {
            return $permissionFailure;
        }

        $filePath = $import->getFilePath();
        if (null === $filePath) {
            return ImportRunReasonCode::MissingFilePath;
        }

        try {
            $filePath = $this->fileStorage->resolve($filePath);
        } catch (ImportFilePathOutsideBaseException $e) {
            $this->importLogger->error('closure.import.file_path.outside_base', [
                'id' => $import->getId(),
                'msg' => $e->getMessage(),
            ]);

            return ImportRunReasonCode::InvalidFilePath;
        }

        return \is_file($filePath) ? $filePath : ImportRunReasonCode::FileNotFound;
    }

    private function markFailed(Import $import, string $reason): void
    {
        $import->setStatus(ImportStatus::FAILED);
        $this->flushWithImportIntent('import.run.failed', $import, ['reason' => $reason]);

        $this->importLogger->error('closure.import.failed.precondition', [
            'id' => $import->getId(),
            'reason' => $reason,
        ]);
    }

    private function dispatchImportOutcome(int $importId, ?string $failureReason = null): void
    {
        $this->entityManager();

        /** @var ImportRepository $importRepository */
        $importRepository = $this->managerRegistry->getRepository(Import::class);
        $import = $importRepository->find($importId);
        if (!$import instanceof Import) {
            return;
        }

        $status = $import->getStatus();
        if (ImportStatus::FAILED === $status) {
            $this->dispatcher->dispatch(new ImportFailed(
                $importId,
                $failureReason ?? 'Import processing failed.',
            ));

            return;
        }

        if ($status?->isFinal() ?? false) {
            $this->dispatcher->dispatch(new ImportCompleted($importId));
        }
    }

    /** @param array<string, mixed> $metadata */
    private function flushWithImportIntent(string $intent, Import $import, array $metadata = []): void
    {
        $em = $this->entityManager();
        $meta = array_merge(['import_id' => $import->getId()], $metadata);
        $this->auditContext->beginIntent($intent, $meta);
        try {
            $this->commitGuard->flush($em);
        } finally {
            $this->auditContext->endIntent();
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        if (!$this->em->isOpen()) {
            $this->managerRegistry->resetManager();
        }

        $em = $this->managerRegistry->getManager();
        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('Expected Doctrine ORM EntityManager.');
        }

        return $em;
    }

    private function runtimeSince(float $started): int
    {
        return (int) \round((\microtime(true) - $started) * 1000.0);
    }

    private function hospitalProfile(int $hospitalId, string $filePath): ClosureHospitalProfile
    {
        $scanReader = $this->rowReaderFactory->createFromCsvFile($filePath);

        return $this->hospitalGuard->profile(
            $hospitalId,
            $this->hospitalRepository->findIdNameMap(),
            $this->hospitalGuard->distinctShortNames($scanReader->rowsAssoc()),
        );
    }

    private function resolvePermissionFailure(Import $import): ?ImportRunReasonCode
    {
        if (ImportType::CLOSURE !== $import->getType()) {
            return ImportRunReasonCode::TypeMismatch;
        }

        return $this->creatorPermissionCheck->failureFor($import);
    }
}
