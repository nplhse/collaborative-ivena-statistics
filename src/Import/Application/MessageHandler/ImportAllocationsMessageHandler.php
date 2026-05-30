<?php

declare(strict_types=1);

namespace App\Import\Application\MessageHandler;

use App\Import\Application\Audit\ImportRunSuppressedAuditClasses;
use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\DTO\ImportSummary;
use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Factory\AllocationImporterFactory;
use App\Import\Application\Factory\RejectWriterFactory;
use App\Import\Application\Factory\RowReaderFactory;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Service\ImportPreviousRunCleanupService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Service\ImportEvaluation;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Shared\Infrastructure\Audit\AuditContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsMessageHandler]
final readonly class ImportAllocationsMessageHandler
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private ImportRepository $importRepository,
        private EntityManagerInterface $em,
        private AllocationImporterFactory $importFactory,
        private RowReaderFactory $rowReaderFactory,
        private RejectWriterFactory $rejectWriterFactory,
        private LoggerInterface $importLogger,
        private EventDispatcherInterface $dispatcher,
        private ImportPreviousRunCleanupService $previousRunCleanupService,
        private AuditContext $auditContext,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function __invoke(ImportAllocationsMessage $message): void
    {
        $import = $this->importRepository->findOneBy(['id' => $message->importId]);
        if (!$import instanceof Import) {
            $this->importLogger->error('import.not_found', ['id' => $message->importId]);

            return;
        }

        $filePath = $import->getFilePath();
        if (null === $filePath) {
            $this->markFailed($import, 'Import has no file path');

            return;
        }

        $filePath = $this->resolvePath($filePath);
        if (!\is_file($filePath)) {
            $this->markFailed($import, 'CSV not found: '.$filePath);

            return;
        }

        $this->auditContext->pushSuppressedEntityAudit(ImportRunSuppressedAuditClasses::fqcnList());
        try {
            if ($import->hasRunBefore()) {
                $this->cleanupPreviousRun($import);
            }

            $import->markAsRunning();
            $this->flushWithImportIntent('import.run.started', $import);

            $reader = $this->rowReaderFactory->createFromCsvFile($filePath);
            $writer = $this->rejectWriterFactory->create();
            $writer->start($import);

            try {
                $this->run($import, $reader, $writer);

                $this->dispatcher->dispatch(new ImportCompleted($message->importId));
            } catch (\Throwable $e) {
                $this->importLogger->critical('import.failed', [
                    'id' => $import->getId(),
                    'ex' => $e::class,
                    'msg' => $e->getMessage(),
                ]);
            }
        } finally {
            $this->auditContext->popSuppressedEntityAudit();
        }
    }

    /** @psalm-suppress PossiblyUnusedReturnValue */
    public function run(Import $import, RowReaderInterface $reader, RejectWriterInterface $writer): ImportSummary
    {
        $started = \microtime(true);

        try {
            $importer = $this->importFactory->create($reader, $writer);
            $summary = $importer->import($import);

            $this->em->clear();
            $fresh = $this->importRepository->find($import->getId());
            if (!$fresh instanceof Import) {
                throw new \RuntimeException('Import not found after refresh');
            }

            $absRejectPath = $writer->getPath();
            if ($summary->rejected > 0 && \is_string($absRejectPath) && '' !== $absRejectPath) {
                $rel = Path::makeRelative($absRejectPath, $this->projectDir);
                $fresh->setRejectFilePath(\str_replace('\\', '/', $rel));
            }

            $runtimeMs = (int) \round((\microtime(true) - $started) * 1000.0);

            ImportEvaluation::apply($fresh, $summary, $runtimeMs);

            $this->flushWithImportIntent('import.run.finished', $fresh);

            return $summary;
        } catch (\Throwable $e) {
            $runtimeMs = (int) \round((\microtime(true) - $started) * 1000.0);

            $import->markAsFailed($runtimeMs);
            $this->flushWithImportIntent('import.run.failed', $import, ['reason' => $e->getMessage()]);

            $this->importLogger->error('import.failed.precondition', [
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

        $this->importLogger->error('import.failed.precondition', [
            'id' => $import->getId(),
            'reason' => $reason,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function flushWithImportIntent(string $intent, Import $import, array $metadata = []): void
    {
        $meta = array_merge(['import_id' => $import->getId()], $metadata);
        $this->auditContext->beginIntent($intent, $meta);
        try {
            $this->em->flush();
        } finally {
            $this->auditContext->endIntent();
        }
    }

    private function cleanupPreviousRun(Import $import): void
    {
        $this->previousRunCleanupService->cleanup($import);
    }

    private function resolvePath(string $stored): string
    {
        if ($this->isAbsolutePath($stored)) {
            return $stored;
        }

        return Path::join($this->projectDir, $stored);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ('' === $path) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === $path[0]) {
            return true;
        }

        return (bool) \preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }
}
