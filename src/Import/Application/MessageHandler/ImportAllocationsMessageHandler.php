<?php

namespace App\Import\Application\MessageHandler;

use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\DTO\ImportSummary;
use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Factory\AllocationImporterFactory;
use App\Import\Application\Factory\RejectWriterFactory;
use App\Import\Application\Factory\RowReaderFactory;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Repository\ImportRepository;
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
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function __invoke(ImportAllocationsMessage $message): void
    {
        $import = $this->importRepository->findOneBy(['id' => $message->importId]);
        if (!$import) {
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

        if ($import->hasRunBefore()) {
            $this->cleanupPreviousRun($import);
        }

        $import->markAsRunning();
        $this->em->flush();

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
    }

    public function run(Import $import, RowReaderInterface $reader, RejectWriterInterface $writer): ImportSummary
    {
        $started = \microtime(true);

        try {
            $importer = $this->importFactory->create($reader, $writer);
            $summary = $importer->import($import);

            $this->em->clear();
            $fresh = $this->importRepository->find($import->getId());
            if (!$fresh) {
                throw new \RuntimeException('Import not found after refresh');
            }

            $absRejectPath = $writer->getPath();
            if ($summary->rejected > 0 && \is_string($absRejectPath) && '' !== $absRejectPath) {
                $rel = Path::makeRelative($absRejectPath, $this->projectDir);
                $fresh->setRejectFilePath(\str_replace('\\', '/', $rel));
            }

            $runtimeMs = (int) \round((\microtime(true) - $started) * 1000.0);

            $fresh->markAsCompleted(
                total: $summary->total,
                ok: $summary->ok,
                rejected: $summary->rejected,
                runtimeMs: $runtimeMs,
            );

            $this->em->flush();

            return $summary;
        } catch (\Throwable $e) {
            $runtimeMs = (int) \round((\microtime(true) - $started) * 1000.0);

            $import->markAsFailed($runtimeMs);
            $this->em->flush();

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
        $this->em->flush();

        $this->importLogger->error('import.failed.precondition', [
            'id' => $import->getId(),
            'reason' => $reason,
        ]);
    }

    private function cleanupPreviousRun(Import $import): void
    {
        $rejectPath = $import->getRejectFilePath();
        if (null !== $rejectPath) {
            $absPath = $this->resolvePath($rejectPath);

            if (\is_file($absPath)) {
                @\unlink($absPath);
                $this->importLogger->info('import.reject_file.deleted', [
                    'import_id' => $import->getId(),
                    'path' => $absPath,
                ]);
            }
        }

        $import->resetForReimport();
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

        // Unix: starts with /
        if (DIRECTORY_SEPARATOR === $path[0]) {
            return true;
        }

        // Windows: drive letter + colon
        if (\preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return true;
        }

        return false;
    }
}
