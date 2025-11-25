<?php

namespace App\Import\Application\MessageHandler;

use App\Import\Application\Contracts\AllocationPersisterInterface;
use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\Contracts\RowToDtoMapperInterface;
use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Service\AllocationImporter;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Adapter\SplCsvRejectWriter;
use App\Import\Infrastructure\Adapter\SplCsvRowReader;
use App\Import\Infrastructure\Adapter\SplCsvStreamFactory;
use App\Import\Infrastructure\Charset\EncodingDetector;
use App\Import\Infrastructure\Mapping\AllocationImportFactory;
use App\Import\Infrastructure\Repository\ImportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsMessageHandler]
final class ImportAllocationsMessageHandler
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly ImportRepository $importRepository,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly RowToDtoMapperInterface $mapper,
        private readonly AllocationImportFactory $factory,
        private readonly AllocationPersisterInterface $persister,
        private readonly LoggerInterface $importLogger,
        private readonly Filesystem $filesystem,
        private readonly EventDispatcherInterface $dispatcher,

        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire(param: 'app.rejects_base_dir')] private readonly string $rejectsDir,
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

        $import->setStatus(ImportStatus::RUNNING);
        $this->em->flush();

        $reader = $this->buildReader($filePath);
        $writer = $this->buildRejectWriter((int) $import->getId());

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

    /**
     * @return array{total:int,ok:int,rejected:int}
     */
    public function run(Import $import, RowReaderInterface $reader, RejectWriterInterface $writer): array
    {
        $started = \microtime(true);

        try {
            $importer = new AllocationImporter(
                validator: $this->validator,
                reader: $reader,
                mapper: $this->mapper,
                factory: $this->factory,
                persister: $this->persister,
                rejectWriter: $writer,
                logger: $this->importLogger,
            );

            $summary = $importer->import($import);

            $this->em->clear();
            $fresh = $this->importRepository->find($import->getId());
            if (!$fresh) {
                throw new \RuntimeException('Import not found after refresh');
            }

            $absRejectPath = $writer->getPath();
            if (($summary['rejected'] ?? 0) > 0 && \is_string($absRejectPath) && '' !== $absRejectPath) {
                $rel = Path::makeRelative($absRejectPath, $this->projectDir);
                $fresh->setRejectFilePath(\str_replace('\\', '/', $rel));
            }

            $fresh
                ->setStatus(ImportStatus::COMPLETED)
                ->setRowCount($summary['total'] ?? 0)
                ->setRowsPassed($summary['ok'] ?? 0)
                ->setRowsRejected($summary['rejected'] ?? 0)
                ->setRunCount(($fresh->getRunCount() ?? 0) + 1)
                ->setRunTime((int) \round((\microtime(true) - $started) * 1000.0));

            $this->em->flush();

            return $summary;
        } catch (\Throwable $e) {
            $import
                ->setStatus(ImportStatus::FAILED)
                ->setRunCount(($import->getRunCount() ?? 0) + 1)
                ->setRunTime((int) \round((\microtime(true) - $started) * 1000.0));

            $this->em->flush();

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

    private function buildReader(string $filePath): SplCsvRowReader
    {
        return new SplCsvRowReader(
            new \SplFileObject($filePath, 'r'),
            new EncodingDetector(),
            new SplCsvStreamFactory($this->importLogger)
        );
    }

    private function buildRejectWriter(int $importId): SplCsvRejectWriter
    {
        $subDir = date('Y').'/'.date('m');
        $dirAbs = Path::join($this->rejectsDir, $subDir);

        $this->filesystem->mkdir($dirAbs, 0775);

        $absPath = Path::join(
            $dirAbs,
            sprintf('alloc_import_%d_rejects_%s.csv', $importId, date('Ymd_His'))
        );

        return new SplCsvRejectWriter($absPath, $this->filesystem);
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
