<?php

namespace App\MessageHandler;

use App\Entity\Import;
use App\Enum\ImportStatus;
use App\Message\ImportAllocationsMessage;
use App\Repository\ImportRepository;
use App\Service\Import\Adapter\CsvRejectWriter;
use App\Service\Import\Adapter\SplCsvRowReader;
use App\Service\Import\AllocationImporter;
use App\Service\Import\Contracts\AllocationPersisterInterface;
use App\Service\Import\Contracts\RowToDtoMapperInterface;
use App\Service\Import\Mapping\AllocationImportFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler]
final class ImportAllocationsMessageHandler
{
    public function __construct(
        private readonly ImportRepository $importRepository,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly RowToDtoMapperInterface $mapper,
        private readonly AllocationImportFactory $factory,
        private readonly AllocationPersisterInterface $persister,
        private readonly LoggerInterface $importLogger,

        #[Autowire(param: 'app.imports_base_dir')] private readonly string $importsDir,
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

        if (!is_file($filePath)) {
            $this->markFailed($import, 'CSV not found: '.$filePath);

            return;
        }

        $import->setStatus(ImportStatus::RUNNING);
        $this->em->flush();

        $reader = $this->buildReader($filePath);
        $writer = $this->buildRejectWriter((int) $import->getId());

        try {
            $this->run($import, $reader, $writer);
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
    public function run(Import $import, SplCsvRowReader $reader, CsvRejectWriter $writer): array
    {
        $started = microtime(true);

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

            $import = $this->importRepository->findOneBy(['id' => $import->getId()]);
            if (null === $import) {
                throw new \RuntimeException('Import not found after refresh');
            }

            if ($writer->getCount() > 0) {
                $import->setRejectFilePath($writer->getPath());
            }

            $import
                ->setStatus(ImportStatus::COMPLETED)
                ->setRowCount($summary['total'] ?? 0)
                ->setRowsPassed($summary['ok'] ?? 0)
                ->setRowsRejected($summary['rejected'] ?? 0)
                ->setRunCount(($import->getRunCount() ?? 0) + 1)
                ->setRunTime((int) round((microtime(true) - $started) * 1000.0));

            $this->em->flush();

            return $summary;
        } catch (\Throwable $e) {
            $import
                ->setStatus(ImportStatus::FAILED)
                ->setRunCount(($import->getRunCount() ?? 0) + 1)
                ->setRunTime((int) round((microtime(true) - $started) * 1000.0));

            $this->em->flush();

            throw $e;
        }
    }

    private function buildReader(string $filePath): SplCsvRowReader
    {
        return new SplCsvRowReader(
            new \SplFileObject($filePath, 'r')
        );
    }

    private function buildRejectWriter(int $importId): CsvRejectWriter
    {
        $subDir = date('Y').'/'.date('m');
        $dir = rtrim($this->rejectsDir, '/').'/'.$subDir;

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
        }

        $path = sprintf('%s/alloc_import_%d_rejects_%s.csv', $dir, $importId, date('Ymd_His'));

        return new CsvRejectWriter($path);
    }

    private function resolvePath(string $arg): string
    {
        $isAbsolute = \str_starts_with($arg, DIRECTORY_SEPARATOR)
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $arg); // Windows

        return $isAbsolute ? $arg : $this->importsDir.'/'.ltrim($arg, '/');
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
}
