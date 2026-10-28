<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\Generator\Application\SyntheticImportMarker;
use App\DataFixtures\Generator\Infrastructure\Csv\IvenaCsvFile;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Message\ImportClosuresMessage;
use App\Import\Application\MessageHandler\ImportAllocationsMessageHandler;
use App\Import\Application\MessageHandler\ImportClosuresMessageHandler;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The demo CSV of #791 is a genuine round trip: a month exported by `app:demo:csv` and uploaded through the
 * normal import handlers gives the same allocations and closures as the generator writes for that month.
 */
#[ResetDatabase]
final class DemoMonthCsvRoundTripTest extends KernelTestCase
{
    private const string UNTIL = '2026-09-30';

    private const string MONTH = '2026-08';

    public function testAnExportedMonthImportsToTheRowsTheGeneratorWrites(): void
    {
        $_ENV['FIXTURES_UNTIL'] = $_SERVER['FIXTURES_UNTIL'] = self::UNTIL;
        $directory = sys_get_temp_dir().'/demo-csv-'.bin2hex(random_bytes(4));
        try {
            self::bootKernel();
            $this->load();
            $connection = $this->connection();
            $hospitalId = (int) $connection->fetchOne('SELECT hospital_id FROM import WHERE file_path LIKE \'synthetic/allocations/%\' ORDER BY file_path LIMIT 1');
            $publicId = (string) $connection->fetchOne('SELECT public_id FROM hospital WHERE id = :id', ['id' => $hospitalId]);
            $allocationImportId = $this->importId(SyntheticImportMarker::ALLOCATIONS, $publicId);
            $closureImportId = $this->importId(SyntheticImportMarker::CLOSURES, $publicId);
            $generatedAllocations = $this->allocations($allocationImportId);
            $generatedClosures = $this->closures($closureImportId);
            self::assertNotEmpty($generatedAllocations);
            self::assertNotEmpty($generatedClosures, 'the month has closures, so the closure file is tested too');

            $tester = new CommandTester(new Application(self::$kernel)->find('app:demo:csv'));
            self::assertSame(Command::FAILURE, $tester->execute(['--hospital' => $publicId, '--month' => self::MONTH, '--dir' => $directory]));
            self::assertStringContainsString('already has allocations', $tester->getDisplay(), 'a month with data is refused');
            self::assertSame(Command::FAILURE, $tester->execute(['--hospital' => $publicId, '--dir' => $directory]));
            self::assertStringContainsString('2026-10 ends after 2026-09-30', $tester->getDisplay(), 'the default month follows the newest data and must be complete');

            $this->deleteImport($allocationImportId);
            $this->deleteImport($closureImportId);
            self::assertSame(Command::SUCCESS, $tester->execute(['--hospital' => $publicId, '--month' => self::MONTH, '--dir' => $directory]), $tester->getDisplay());

            $files = glob($directory.'/synthetic-demo_*_'.self::MONTH.'_*.csv') ?: [];
            sort($files);
            self::assertCount(2, $files, 'an allocation and a closure file');
            [$allocationFile, $closureFile] = $files;
            self::assertStringEndsWith('_allocations.csv', $allocationFile);
            self::assertStringEndsWith('_closures.csv', $closureFile);
            $allocationCsv = (string) file_get_contents($allocationFile);
            self::assertSame(\count($generatedAllocations), substr_count($allocationCsv, '"'.IvenaCsvFile::SYNTHETIC_REMARK.'"'), 'every allocation row says it is synthetic');
            self::assertSame(\count($generatedAllocations), substr_count($allocationCsv, ';"SYNTH-'), 'every case number says it is synthetic');
            self::assertSame(\count($generatedClosures), substr_count((string) file_get_contents($closureFile), '"'.IvenaCsvFile::SYNTHETIC_REMARK.'"'));

            $this->entityManager()->clear();
            $hospital = $this->entityManager()->find(Hospital::class, $hospitalId);
            self::assertInstanceOf(Hospital::class, $hospital);
            $owner = $hospital->getOwner();
            self::assertInstanceOf(User::class, $owner);
            $owner->setRoles([...$owner->getRoles(), UserRole::CLOSURE_BETA]);
            $this->entityManager()->flush();

            $uploadedAllocations = $this->upload($allocationFile, ImportType::ALLOCATION, $hospital, $owner);
            self::getContainer()->get(ImportAllocationsMessageHandler::class)(new ImportAllocationsMessage($uploadedAllocations));
            $uploadedClosures = $this->upload($closureFile, ImportType::CLOSURE, $hospital, $owner);
            self::getContainer()->get(ImportClosuresMessageHandler::class)(new ImportClosuresMessage($uploadedClosures));

            // The generator writes infants with age 0; the import accepts ages 1 to 99 and rejects the others.
            $outsideImportAges = array_values(array_filter($generatedAllocations, static fn (array $row): bool => $row['age'] < 1 || $row['age'] > 99));
            $importable = array_values(array_filter($generatedAllocations, static fn (array $row): bool => $row['age'] >= 1 && $row['age'] <= 99));
            self::assertSame(
                ['status' => ImportStatus::COMPLETED->value, 'rows_passed' => \count($importable), 'rows_rejected' => \count($outsideImportAges)],
                $connection->fetchAssociative('SELECT status, rows_passed, rows_rejected FROM import WHERE id = :id', ['id' => $uploadedAllocations]),
            );
            self::assertSame(
                array_fill(0, \count($outsideImportAges), '["age: This value should be between 1 and 99."]'),
                $connection->fetchFirstColumn('SELECT messages FROM import_reject WHERE import_id = :id ORDER BY line_number', ['id' => $uploadedAllocations]),
                'only rows with an age outside the import range are rejected',
            );
            self::assertSame(
                ['status' => ImportStatus::COMPLETED->value, 'rows_passed' => \count($generatedClosures), 'rows_rejected' => 0],
                $connection->fetchAssociative('SELECT status, rows_passed, rows_rejected FROM import WHERE id = :id', ['id' => $uploadedClosures]),
            );
            self::assertSame($importable, $this->allocations($uploadedAllocations), 'the upload gives the rows the generator writes');
            self::assertSame(
                \count($importable),
                (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection WHERE import_id = :id', ['id' => $uploadedAllocations]),
                'the normal projection runs for the upload',
            );
            self::assertSame($generatedClosures, $this->closures($uploadedClosures));
            self::assertSame(
                [IvenaCsvFile::SYNTHETIC_REMARK],
                $connection->fetchFirstColumn('SELECT DISTINCT notes FROM allocation WHERE import_id = :id', ['id' => $uploadedAllocations]),
                'the allocations keep the synthetic remark',
            );
        } finally {
            unset($_ENV['FIXTURES_UNTIL'], $_SERVER['FIXTURES_UNTIL']);
            new Filesystem()->remove($directory);
        }
    }

    /**
     * Copies a file under the import directory and registers it like the upload form does.
     */
    private function upload(string $file, ImportType $type, Hospital $hospital, User $creator): int
    {
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $target = (string) self::getContainer()->getParameter('app.imports_base_dir').'/_tests/demo-csv/'.bin2hex(random_bytes(4)).'/'.basename($file);
        new Filesystem()->copy($file, $target);

        $em = $this->entityManager();
        $import = new Import()
            ->setName('Demo upload '.$type->value)
            ->setHospital($em->getReference(Hospital::class, (int) $hospital->getId()))
            ->setCreatedBy($em->getReference(User::class, (int) $creator->getId()))
            ->setType($type)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath(ltrim(substr($target, \strlen($projectDir)), '/'))
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize((int) filesize($target))
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);
        $this->entityManager()->persist($import);
        $this->entityManager()->flush();

        return (int) $import->getId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allocations(int $importId): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT a.created_at, a.arrival_at, a.gender, a.age, a.urgency, a.transport_type, a.requires_resus,
                    a.requires_cathlab, a.is_cpr, a.is_ventilated, a.is_shock, a.is_pregnant, a.is_with_physician,
                    a.is_work_accident, a.department_was_closed, a.hospital_id, a.dispatch_area_id, a.state_id,
                    s.public_id AS speciality, d.public_id AS department, r.public_id AS indication_raw,
                    a.indication_normalized_id, a.assignment_id, a.occasion_id, a.infection_id, a.secondary_transport_id
             FROM allocation a
             INNER JOIN speciality s ON s.id = a.speciality_id
             INNER JOIN department d ON d.id = a.department_id
             INNER JOIN indication_raw r ON r.id = a.indication_raw_id
             WHERE a.import_id = :id
             ORDER BY a.created_at, a.arrival_at, a.age, a.gender, d.public_id, r.public_id, a.urgency, a.transport_type, a.occasion_id, a.assignment_id',
            ['id' => $importId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function closures(int $importId): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT c.starts_at, c.ends_at, c.care_level, c.reason, c.facility_kind, c.source_group_id, c.hospital_id,
                    s.public_id AS speciality, d.public_id AS department
             FROM closure_interval c
             INNER JOIN speciality s ON s.id = c.speciality_id
             INNER JOIN department d ON d.id = c.department_id
             WHERE c.import_id = :id ORDER BY c.starts_at, d.public_id',
            ['id' => $importId],
        );
    }

    private function deleteImport(int $importId): void
    {
        foreach (['allocation_stats_projection WHERE import_id', 'mci_case WHERE import_id', 'allocation WHERE import_id', 'closure_interval WHERE import_id', 'import WHERE id'] as $where) {
            $this->connection()->executeStatement(sprintf('DELETE FROM %s = :id', $where), ['id' => $importId]);
        }
    }

    private function importId(string $type, string $publicId): int
    {
        return (int) $this->connection()->fetchOne('SELECT id FROM import WHERE file_path LIKE :marker', ['marker' => SyntheticImportMarker::likeAnySeed($type, $publicId, self::MONTH)]);
    }

    private function load(): void
    {
        /** @var ContainerInterface $container */
        $container = self::getContainer()->get('test.service_container'); // @phpstan-ignore symfonyContainer.serviceNotFound
        /** @var SymfonyFixturesLoader $loader */
        $loader = $container->get('doctrine.fixtures.loader');
        /** @var ORMPurgerFactory $purgerFactory */
        $purgerFactory = $container->get('doctrine.fixtures.purger.orm_purger_factory');

        new ORMExecutor($this->entityManager(), $purgerFactory->createForEntityManager('default', $this->entityManager(), []))->execute($loader->getFixtures(['allocations']), false);
        $this->entityManager()->clear();
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
