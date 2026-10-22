<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\DTO\ImportRunOutcome;
use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Event\ImportFailed;
use App\Import\Application\Message\ImportRunMessageInterface;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportRunStatus;
use App\Import\Domain\Enum\ImportStatus;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Registers, starts and ends import runs (ADR 016).
 *
 * - Registering a run locks the import row, supersedes the previous unfinished run and points
 *   Import::currentRunId to the new run, all in one transaction.
 * - Ending a run is a conditional update from an open status. Only the caller that wins it
 *   increments Import::runCount, commits the import result and dispatches ImportCompleted / ImportFailed.
 */
final readonly class ImportRunLifecycle
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private EventDispatcherInterface $dispatcher,
        private AuditContext $auditContext,
        private LoggerInterface $importLogger,
    ) {
    }

    public function register(int $importId, ?int $initiatedByUserId = null, ?ImportBatchRunItem $batchRunItem = null): ImportRun
    {
        $em = $this->entityManager();

        return $em->wrapInTransaction(function (EntityManagerInterface $em) use ($importId, $initiatedByUserId, $batchRunItem): ImportRun {
            $import = $em->find(Import::class, $importId);
            if (!$import instanceof Import) {
                throw new \InvalidArgumentException(\sprintf('Import #%d does not exist.', $importId));
            }

            // Lock the row first, then reload it, so current_run_id and run_count are the committed values.
            ImportRunCommitGuard::lockCurrentRunId($em->getConnection(), $importId);
            $em->refresh($import);

            $now = new \DateTimeImmutable();
            $this->supersedeCurrentRun($em, $import, $now);

            $initiator = null !== $initiatedByUserId ? $em->getReference(User::class, $initiatedByUserId) : null;
            $run = new ImportRun(Uuid::v7(), $import, $initiator, $now);
            $em->persist($run);
            $import->setCurrentRunId($run->getId());
            $batchRunItem?->setImportRun($run);

            $this->flushWithIntent($em, 'import.run.queued', $importId, $run->getId());

            $this->importLogger->info('import.run.queued', [
                'id' => $importId,
                'run_id' => $run->getId()->toRfc4122(),
                'initiated_by' => $initiatedByUserId,
                'batch_item_id' => $batchRunItem?->getId(),
            ]);

            return $run;
        });
    }

    /**
     * Claims a message for processing. Returns null when the message must be acknowledged without
     * processing: its run is not the import's current run any more, or the run already finished.
     */
    public function start(ImportRunMessageInterface $message): ?ImportRunContext
    {
        $runId = $this->parseRunId($message);
        if (!$runId instanceof Uuid) {
            return null;
        }

        $importId = $message->getImportId();
        $em = $this->entityManager();

        return $em->wrapInTransaction(function (EntityManagerInterface $em) use ($importId, $runId): ?ImportRunContext {
            $currentRunId = ImportRunCommitGuard::lockCurrentRunId($em->getConnection(), $importId);
            if (false === $currentRunId) {
                $this->importLogger->error('import.not_found', ['id' => $importId, 'run_id' => $runId->toRfc4122()]);

                return null;
            }

            if (null === $currentRunId || strtolower($currentRunId) !== $runId->toRfc4122()) {
                $this->importLogger->info('import.run.stale_message', [
                    'id' => $importId,
                    'run_id' => $runId->toRfc4122(),
                    'current_run_id' => $currentRunId,
                ]);

                return null;
            }

            $run = $this->findFresh($em, $runId);
            if (!$run instanceof ImportRun || $run->isFinished()) {
                $this->importLogger->info('import.run.already_finished', [
                    'id' => $importId,
                    'run_id' => $runId->toRfc4122(),
                    'status' => $run?->getStatus()->value,
                ]);

                return null;
            }

            $resumed = ImportRunStatus::Running === $run->getStatus();
            $run->markRunning(new \DateTimeImmutable());
            $em->flush();

            return new ImportRunContext($importId, $runId, $resumed);
        });
    }

    /**
     * Commits the evaluated result of a run and ends it.
     *
     * @param \Closure(Import): ImportRunOutcome $evaluate applies the result to the freshly loaded, locked import
     *
     * @return ImportRunOutcome|null null when the run was superseded or already ended; nothing was committed then
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function finish(ImportRunContext $context, \Closure $evaluate): ?ImportRunOutcome
    {
        return $this->end($context, $evaluate, 'import.run.finished', []);
    }

    /**
     * Ends a run as failed with a reason code.
     *
     * @param (\Closure(Import): void)|null $applyToImport changes the import; defaults to status FAILED and runCount + 1
     *
     * @return bool whether this call ended the run
     */
    public function fail(ImportRunContext $context, ImportRunReasonCode $reasonCode, ?\Closure $applyToImport = null): bool
    {
        $outcome = $this->end(
            $context,
            static function (Import $import) use ($reasonCode, $applyToImport): ImportRunOutcome {
                if ($applyToImport instanceof \Closure) {
                    $applyToImport($import);
                } else {
                    $import->setStatus(ImportStatus::FAILED);
                    $import->incrementRunCount();
                }

                return ImportRunOutcome::failed($reasonCode);
            },
            'import.run.failed',
            ['reason' => $reasonCode->value],
        );

        if (!$outcome instanceof ImportRunOutcome) {
            return false;
        }

        $this->importLogger->error('import.run.failed', [
            'id' => $context->importId,
            'run_id' => $context->runId->toRfc4122(),
            'reason' => $reasonCode->value,
        ]);

        return true;
    }

    /**
     * Ends the run of a message that Messenger gave up on.
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function failAfterRetriesExhausted(ImportRunMessageInterface $message): bool
    {
        $runId = $this->parseRunId($message);
        if (!$runId instanceof Uuid) {
            return false;
        }

        return $this->fail(
            new ImportRunContext($message->getImportId(), $runId, true),
            ImportRunReasonCode::RetriesExhausted,
        );
    }

    /**
     * Ends a registered run whose message could not be dispatched.
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function failDispatch(ImportRun $run): bool
    {
        $importId = $run->getImport()->getId();
        if (null === $importId) {
            return false;
        }

        return $this->fail(
            new ImportRunContext($importId, $run->getId()),
            ImportRunReasonCode::DispatchFailed,
        );
    }

    /**
     * @param \Closure(Import): ImportRunOutcome $evaluate
     * @param array<string, mixed>               $auditMetadata
     */
    private function end(ImportRunContext $context, \Closure $evaluate, string $auditIntent, array $auditMetadata): ?ImportRunOutcome
    {
        $em = $this->entityManager();
        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            if (!ImportRunCommitGuard::isCurrentRunLocked($connection, $context)) {
                $connection->rollBack();
                $this->importLogger->info('import.run.not_current', [
                    'id' => $context->importId,
                    'run_id' => $context->runId->toRfc4122(),
                ]);

                return null;
            }

            $import = $em->find(Import::class, $context->importId);
            if (!$import instanceof Import) {
                $connection->rollBack();

                return null;
            }

            // The row is locked now; work on its committed state, not on a stale copy in the identity map.
            $em->refresh($import);
            $outcome = $evaluate($import);

            if (!$this->transitionToTerminal($em, $context->runId, $outcome, new \DateTimeImmutable())) {
                $connection->rollBack();
                $em->refresh($import);
                $this->importLogger->info('import.run.already_finished', [
                    'id' => $context->importId,
                    'run_id' => $context->runId->toRfc4122(),
                ]);

                return null;
            }

            $this->flushWithIntent($em, $auditIntent, $context->importId, $context->runId, $auditMetadata);
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }

        $this->dispatchOutcome($context, $outcome);

        return $outcome;
    }

    private function supersedeCurrentRun(EntityManagerInterface $em, Import $import, \DateTimeImmutable $now): void
    {
        $currentRunId = $import->getCurrentRunId();
        if (!$currentRunId instanceof Uuid) {
            return;
        }

        $previous = $this->findFresh($em, $currentRunId);
        if (!$previous instanceof ImportRun || $previous->isFinished()) {
            return;
        }

        if (!$this->transitionToTerminal($em, $currentRunId, new ImportRunOutcome(ImportRunStatus::Superseded), $now)) {
            return;
        }

        $em->refresh($previous);
        $import->incrementRunCount();
        $previous->getBatchRunItem()?->markSuperseded();

        $this->importLogger->info('import.run.superseded', [
            'id' => $import->getId(),
            'run_id' => $currentRunId->toRfc4122(),
        ]);
    }

    /**
     * The single place where a run reaches a terminal status: a conditional update from an open status.
     */
    private function transitionToTerminal(EntityManagerInterface $em, Uuid $runId, ImportRunOutcome $outcome, \DateTimeImmutable $now): bool
    {
        if (!$outcome->status->isTerminal()) {
            throw new \InvalidArgumentException(\sprintf('Run status "%s" is not terminal.', $outcome->status->value));
        }

        $updated = $em->createQuery(\sprintf(
            'UPDATE %s run
             SET run.status = :status,
                 run.finishedAt = :finishedAt,
                 run.rowsTotal = :rowsTotal,
                 run.rowsOk = :rowsOk,
                 run.rowsRejected = :rowsRejected,
                 run.reasonCode = :reasonCode
             WHERE run.id = :id AND run.status IN (:openStatuses)',
            ImportRun::class,
        ))
            ->setParameter('status', $outcome->status->value)
            ->setParameter('finishedAt', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('rowsTotal', $outcome->rowsTotal, Types::INTEGER)
            ->setParameter('rowsOk', $outcome->rowsOk, Types::INTEGER)
            ->setParameter('rowsRejected', $outcome->rowsRejected, Types::INTEGER)
            ->setParameter('reasonCode', $outcome->reasonCode?->value, Types::STRING)
            ->setParameter('id', $runId, UuidType::NAME)
            ->setParameter(
                'openStatuses',
                array_map(static fn (ImportRunStatus $status): string => $status->value, ImportRunStatus::openStatuses()),
                ArrayParameterType::STRING,
            )
            ->execute();

        return 1 === $updated;
    }

    private function dispatchOutcome(ImportRunContext $context, ImportRunOutcome $outcome): void
    {
        $runId = $context->runId->toRfc4122();

        match ($outcome->status) {
            ImportRunStatus::Completed, ImportRunStatus::Partial => $this->dispatcher->dispatch(
                new ImportCompleted($context->importId, $runId),
            ),
            ImportRunStatus::Failed => $this->dispatcher->dispatch(new ImportFailed(
                $context->importId,
                $outcome->reasonCode?->describe(),
                $runId,
                $outcome->reasonCode,
            )),
            ImportRunStatus::Queued, ImportRunStatus::Running, ImportRunStatus::Superseded => null,
        };
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function flushWithIntent(EntityManagerInterface $em, string $intent, int $importId, Uuid $runId, array $metadata = []): void
    {
        $this->auditContext->beginIntent($intent, array_merge([
            'import_id' => $importId,
            'run_id' => $runId->toRfc4122(),
        ], $metadata));

        try {
            $em->flush();
        } finally {
            $this->auditContext->endIntent();
        }
    }

    /**
     * Terminal transitions are DQL updates, so a run in the identity map can be stale. Callers hold the
     * import row lock, which makes the reloaded state authoritative.
     */
    private function findFresh(EntityManagerInterface $em, Uuid $runId): ?ImportRun
    {
        $run = $em->find(ImportRun::class, $runId);
        if ($run instanceof ImportRun) {
            $em->refresh($run);
        }

        return $run;
    }

    private function parseRunId(ImportRunMessageInterface $message): ?Uuid
    {
        $runId = $message->getRunId();
        if (null === $runId) {
            return null;
        }

        if (!Uuid::isValid($runId)) {
            $this->importLogger->error('import.run.invalid_run_id', [
                'id' => $message->getImportId(),
                'run_id' => $runId,
            ]);

            return null;
        }

        return Uuid::fromString($runId);
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
}
