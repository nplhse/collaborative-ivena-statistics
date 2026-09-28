<?php

declare(strict_types=1);

namespace App\Import\Application\MessageHandler;

use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Import\Application\Audit\ImportRunSuppressedAuditClasses;
use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\DTO\ImportSummary;
use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Event\ImportFailed;
use App\Import\Application\Exception\ImportFilePathOutsideBaseException;
use App\Import\Application\Factory\ClosureImporterFactory;
use App\Import\Application\Factory\RejectWriterFactory;
use App\Import\Application\Factory\RowReaderFactory;
use App\Import\Application\Message\ImportClosuresMessage;
use App\Import\Application\Service\ImportFileStorage;
use App\Import\Application\Service\ImportPreviousRunCleanupService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Domain\Service\ImportEvaluation;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
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
        private HospitalPermissionAccess $hospitalPermissionAccess,
        private AuditContext $auditContext,
        private ManagerRegistry $managerRegistry,
        private ImportFileStorage $fileStorage,
    ) {
    }

    public function __invoke(ImportClosuresMessage $message): void
    {
        $import = $this->importRepository->findOneBy(['id' => $message->importId]);
        if (!$import instanceof Import) {
            $this->importLogger->error('closure.import.not_found', ['id' => $message->importId]);

            return;
        }

        $reason = $this->resolvePermissionFailureReason($import);
        if (null !== $reason) {
            $this->markFailed($import, $reason);
            $this->dispatchImportOutcome($message->importId, $reason);

            return;
        }

        $filePath = $import->getFilePath();
        if (null === $filePath) {
            $this->markFailed($import, 'Import has no file path');
            $this->dispatchImportOutcome($message->importId, 'Import has no file path');

            return;
        }

        try {
            $filePath = $this->fileStorage->resolve($filePath);
        } catch (ImportFilePathOutsideBaseException $e) {
            $reason = 'Invalid import file path';
            $this->importLogger->error('closure.import.file_path.outside_base', [
                'id' => $message->importId,
                'msg' => $e->getMessage(),
            ]);
            $this->markFailed($import, $reason);
            $this->dispatchImportOutcome($message->importId, $reason);

            return;
        }

        if (!\is_file($filePath)) {
            $reason = 'CSV not found: '.$filePath;
            $this->markFailed($import, $reason);
            $this->dispatchImportOutcome($message->importId, $reason);

            return;
        }

        $this->auditContext->pushSuppressedEntityAudit(ImportRunSuppressedAuditClasses::fqcnList());
        try {
            if ($import->hasRunBefore()) {
                $this->previousRunCleanupService->cleanup($import);
            }

            $import->markAsRunning();
            $this->flushWithImportIntent('import.run.started', $import);

            $reader = $this->rowReaderFactory->createFromCsvFile($filePath);
            $writer = $this->rejectWriterFactory->create();
            $writer->start($import);

            try {
                $this->run($import, $reader, $writer);
            } catch (\Throwable $e) {
                $this->importLogger->critical('closure.import.failed', [
                    'id' => $import->getId(),
                    'ex' => $e::class,
                    'msg' => $e->getMessage(),
                ]);
                $this->dispatchImportOutcome($message->importId, $e->getMessage());

                return;
            }

            $this->dispatchImportOutcome($message->importId);
        } finally {
            $this->auditContext->popSuppressedEntityAudit();
        }
    }

    public function run(Import $import, RowReaderInterface $reader, RejectWriterInterface $writer): ImportSummary
    {
        $started = \microtime(true);
        $summary = ImportSummary::empty();

        try {
            $importer = $this->importFactory->create($reader, $writer);
            $summary = $importer->import($import);

            $this->entityManager()->clear();
            $fresh = $this->importRepository->find($import->getId());
            if (!$fresh instanceof Import) {
                throw new \RuntimeException('Import not found after refresh');
            }

            $absRejectPath = $writer->getPath();
            if ($summary->rejected > 0 && \is_string($absRejectPath) && '' !== $absRejectPath) {
                $fresh->setRejectFilePath($this->fileStorage->toRelative($absRejectPath));
            }

            $runtimeMs = (int) \round((\microtime(true) - $started) * 1000.0);
            ImportEvaluation::apply($fresh, $summary, $runtimeMs);
            $this->flushWithImportIntent('import.run.finished', $fresh);

            return $summary;
        } catch (\Throwable $e) {
            $runtimeMs = (int) \round((\microtime(true) - $started) * 1000.0);
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
            $em->flush();
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

    private function resolvePermissionFailureReason(Import $import): ?string
    {
        if (ImportType::CLOSURE !== $import->getType()) {
            return 'Import type is not closure';
        }

        $createdBy = $import->getCreatedBy();
        if (!$createdBy instanceof User) {
            return 'Import has no creator user';
        }

        if (!\in_array(UserRole::CLOSURE_BETA, $createdBy->getRoles(), true)) {
            return 'Creator has no closure beta role';
        }

        $hospital = $import->getHospital();
        $hospitalId = $hospital?->getId();
        if (null === $hospitalId) {
            return 'Import has no hospital';
        }

        if (!$this->hospitalPermissionAccess->hasPermission($createdBy, $hospitalId, HospitalPermission::Import)) {
            return 'Creator has no current import permission';
        }

        return null;
    }
}
