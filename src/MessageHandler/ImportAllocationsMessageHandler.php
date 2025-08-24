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

        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private string $importsDir = '',
        private string $rejectsDir = '',
    ) {
        $this->importsDir = $this->projectDir.'/';
        $this->rejectsDir = $this->projectDir.'/var/import/rejects';
    }

    /**
     * Vorbereitung: Import laden, Pfad prüfen/auflösen, Status Running, IO bauen → run().
     */
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
     * Ausführung: Importer mit IO starten, Summary speichern.
     *
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

            $import
                ->setStatus(ImportStatus::COMPLETED)
                ->setRowCount($summary['total'] ?? 0)
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
            new \SplFileObject($filePath, 'r'),
            delimiter: ';',
            enclosure: '"',
            escape: '\\',
            inputEncoding: 'UTF-8'
        );
    }

    private function buildRejectWriter(int $importId): CsvRejectWriter
    {
        if (!is_dir($this->rejectsDir) && !@mkdir($this->rejectsDir, 0775, true) && !is_dir($this->rejectsDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->rejectsDir));
        }

        $path = sprintf('%s/rejects_%d_%s.csv', $this->rejectsDir, $importId, date('Ymd_His'));

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
