<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Import\ImportEncountersDispatcher;
use App\Encounter\Domain\Entity\EncounterImport;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Stores a synthetic encounter file like an upload and runs the import synchronously (messenger
 * routes ImportEncountersMessage to `sync` in the test environment). Needs a booted kernel and the
 * EncounterTestFixtures trait. Call removeEncounterFiles() in tearDown().
 */
trait EncounterImportRunner
{
    /** @var list<string> */
    private array $encounterFiles = [];

    private function uploadEncounterFile(Hospital $hospital, User $creator, string $content, string $name, int $keyGeneration = 1): int
    {
        $importId = $this->storeEncounterFile($hospital, $creator, $content, $name, $keyGeneration);
        $this->runEncounterImport($importId, ImportRunTrigger::Upload, (int) $creator->getId());

        return $importId;
    }

    /**
     * Stores the file and the pending import like an upload, without registering or running a run.
     */
    private function storeEncounterFile(Hospital $hospital, User $creator, string $content, string $name, int $keyGeneration = 1): int
    {
        $storedPath = sprintf('var/imports/test-encounter/%s.csv', bin2hex(random_bytes(8)));
        $absolutePath = $this->projectDir().'/'.$storedPath;
        new Filesystem()->dumpFile($absolutePath, $content);
        $this->encounterFiles[] = $absolutePath;

        $em = $this->entityManager();
        /** @var Hospital $hospitalReference */
        $hospitalReference = $em->getReference(Hospital::class, (int) $hospital->getId());
        /** @var User $creatorReference */
        $creatorReference = $em->getReference(User::class, (int) $creator->getId());
        $import = new Import()
            ->setName($name)
            ->setHospital($hospitalReference)
            ->setCreatedBy($creatorReference)
            ->setType(ImportType::ENCOUNTER)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($storedPath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(\strlen($content))
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);
        $em->persist($import);
        $em->persist(new EncounterImport($import, 'ed_visit', $keyGeneration, $creatorReference, new \DateTimeImmutable('2026-11-01 10:00')));
        $em->flush();

        return (int) $import->getId();
    }

    private function runEncounterImport(int $importId, ImportRunTrigger $trigger = ImportRunTrigger::Reprocess, ?int $userId = null): void
    {
        $dispatcher = self::getContainer()->get(ImportEncountersDispatcher::class);
        self::assertInstanceOf(ImportEncountersDispatcher::class, $dispatcher);
        $dispatcher->dispatch($importId, $trigger, $userId);
        $this->entityManager()->clear();
    }

    private function encounterImportOf(int $importId): EncounterImport
    {
        $em = $this->entityManager();
        $em->clear();
        $encounterImport = $em->getRepository(EncounterImport::class)->findOneBy(['import' => $importId]);
        self::assertInstanceOf(EncounterImport::class, $encounterImport);

        return $encounterImport;
    }

    private function sourceFileExists(int $importId): bool
    {
        $import = $this->entityManager()->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $import);

        return is_file($this->projectDir().'/'.$import->getFilePath());
    }

    /**
     * @return array<string, int> visit label => encounter id, for the labels of EdVisitFileBuilder
     */
    private function visitIdsOfImport(int $importId, string ...$labels): array
    {
        $ids = [];
        foreach ($labels as $label) {
            $id = $this->connection()->fetchOne(
                'SELECT id FROM encounter WHERE import_id = ? AND visit_fingerprint = ?',
                [$importId, EdVisitFileBuilder::fingerprint($label)],
            );
            if (false !== $id) {
                $ids[$label] = (int) $id;
            }
        }

        return $ids;
    }

    private function encounterCount(int $importId): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter WHERE import_id = ?', [$importId]);
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function removeEncounterFiles(): void
    {
        new Filesystem()->remove($this->encounterFiles);
        $this->encounterFiles = [];
    }

    private function projectDir(): string
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);

        return $projectDir;
    }

    abstract private function entityManager(): EntityManagerInterface;
}
