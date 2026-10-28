<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Application\HospitalMonthGenerator;
use App\DataFixtures\Generator\Application\HospitalProfileResolver;
use App\DataFixtures\Generator\Application\SyntheticImportMarker;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * A month added later equals the same month of a full load (ADR 022).
 */
#[ResetDatabase]
final class HospitalMonthGeneratorTest extends KernelTestCase
{
    private const string UNTIL = '2026-09-30';

    private const string MONTH = '2026-08';

    public function testAMonthAddedLaterEqualsTheMonthOfAFullLoad(): void
    {
        $_ENV['FIXTURES_UNTIL'] = $_SERVER['FIXTURES_UNTIL'] = self::UNTIL;
        try {
            self::bootKernel();
            $this->load();
            $connection = $this->connection();

            $hospitalId = (int) $connection->fetchOne('SELECT hospital_id FROM import WHERE file_path LIKE \'synthetic/allocations/%\' ORDER BY file_path LIMIT 1');
            $publicId = (string) $connection->fetchOne('SELECT public_id FROM hospital WHERE id = :id', ['id' => $hospitalId]);
            $importId = $this->importId(SyntheticImportMarker::ALLOCATIONS, $publicId);
            $closureImportId = $this->importId(SyntheticImportMarker::CLOSURES, $publicId);
            self::assertGreaterThan(0, $importId, 'the full load has an allocation import for the month');
            self::assertGreaterThan(0, $closureImportId, 'the full load has a closure import for the month');

            $loaded = $this->describeMonth($importId, $closureImportId);
            $others = $this->describeOthers($importId);
            $this->deleteImport($importId);
            $this->deleteImport($closureImportId);

            $generator = $this->generator();
            $volume = $this->volume();
            $generated = $generator->generate($volume, $this->hospital($hospitalId), self::MONTH, $volume->lastDay(new MockClock()));

            self::assertSame($loaded, $this->describeMonth($generated->importId, $generated->closureImportId), 'same rows as inside the full load');
            self::assertSame(\count($loaded['closures']), $generated->closures);
            self::assertSame($others, $this->describeOthers($generated->importId), 'other imports are left alone');
            self::assertSame(\count($loaded['allocations']), $generated->allocations);

            $this->expectException(\DomainException::class);
            $generator->generate($volume, $this->hospital($hospitalId), self::MONTH, $volume->lastDay(new MockClock()));
        } finally {
            unset($_ENV['FIXTURES_UNTIL'], $_SERVER['FIXTURES_UNTIL']);
        }
    }

    public function testTheCommandRefusesAnExistingMonthAndPlansANewOne(): void
    {
        $_ENV['FIXTURES_UNTIL'] = $_SERVER['FIXTURES_UNTIL'] = self::UNTIL;
        try {
            self::bootKernel();
            $this->load();
            $publicId = (string) $this->connection()->fetchOne('SELECT h.public_id FROM import i JOIN hospital h ON h.id = i.hospital_id WHERE i.file_path LIKE \'synthetic/allocations/%\' ORDER BY i.file_path LIMIT 1');
            $tester = new CommandTester(new Application(self::$kernel)->find('app:generate:imports'));
            $this->assertKpisMatchTheUploads('after the full load');

            self::assertSame(Command::FAILURE, $tester->execute(['--hospital' => [$publicId], '--month' => self::MONTH]));
            self::assertStringContainsString('already has allocations', $tester->getDisplay());
            /** @var EntityManagerInterface $entityManager */
            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            /** @var HospitalProfileResolver $profiles */
            $profiles = self::getContainer()->get(HospitalProfileResolver::class);
            /** @var FixtureVolumeResolver $volumes */
            $volumes = self::getContainer()->get(FixtureVolumeResolver::class);
            $hospital = $entityManager->find(Hospital::class, (int) $this->connection()->fetchOne('SELECT id FROM hospital WHERE public_id = :id', ['id' => $publicId]));
            self::assertInstanceOf(Hospital::class, $hospital);
            self::assertStringContainsString($profiles->resolve($hospital, $volumes->resolve()->seed)->configuration, $tester->getDisplay(), 'the table shows the structure of the hospital');

            self::assertSame(Command::FAILURE, $tester->execute(['--hospital' => [$publicId], '--month' => '2026-10']));
            self::assertStringContainsString('lies after the last day', $tester->getDisplay());

            $imports = (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import');
            self::assertSame(Command::FAILURE, $tester->execute(['--hospital' => ['00000000-0000-0000-0000-000000000000'], '--month' => self::MONTH]));
            self::assertSame($imports, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import'), 'nothing was written');

            // A free month is generated and runs through the pipeline: projection and closure analysis.
            $this->deleteImport($this->importId(SyntheticImportMarker::ALLOCATIONS, $publicId));
            $this->deleteImport($this->importId(SyntheticImportMarker::CLOSURES, $publicId));
            self::assertSame(Command::SUCCESS, $tester->execute(['--hospital' => [$publicId], '--month' => self::MONTH]), $tester->getDisplay());
            $importId = $this->importId(SyntheticImportMarker::ALLOCATIONS, $publicId);
            self::assertGreaterThan(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection WHERE import_id = :id', ['id' => $importId]));
            self::assertGreaterThan(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM closure_event e JOIN hospital h ON h.id = e.hospital_id WHERE h.public_id = :id', ['id' => $publicId]));
            $uploadDay = (string) $this->connection()->fetchOne('SELECT CAST(created_at AS date) FROM import WHERE id = :id', ['id' => $importId]);
            self::assertGreaterThan(self::MONTH.'-31', $uploadDay, 'uploaded after the month');
            self::assertLessThanOrEqual(self::UNTIL, $uploadDay, 'not after the last day of the period');
            $this->assertKpisMatchTheUploads('after a month added later');
        } finally {
            unset($_ENV['FIXTURES_UNTIL'], $_SERVER['FIXTURES_UNTIL']);
        }
    }

    /**
     * @return array{allocations: list<array<string, mixed>>, mci: list<array<string, mixed>>, closures: list<array<string, mixed>>, import: array<string, mixed>|false, closure_import: array<string, mixed>|false}
     */
    private function describeMonth(int $importId, int $closureImportId): array
    {
        $connection = $this->connection();

        return [
            'import' => $connection->fetchAssociative('SELECT name, file_path, row_count, status, created_at FROM import WHERE id = :id', ['id' => $importId]),
            'closure_import' => $connection->fetchAssociative('SELECT name, file_path, row_count, status, type, created_at FROM import WHERE id = :id', ['id' => $closureImportId]),
            'closures' => $connection->fetchAllAssociative(
                'SELECT c.starts_at, c.ends_at, c.care_level, c.reason, c.source_group_id, d.public_id AS department
                 FROM closure_interval c INNER JOIN department d ON d.id = c.department_id
                 WHERE c.import_id = :id ORDER BY c.starts_at, d.public_id',
                ['id' => $closureImportId],
            ),
            'allocations' => $connection->fetchAllAssociative(
                'SELECT a.created_at, a.arrival_at, a.gender, a.age, a.urgency, a.transport_type, a.requires_resus,
                        a.requires_cathlab, a.is_cpr, a.department_was_closed, d.public_id AS department, r.public_id AS indication_raw
                 FROM allocation a
                 INNER JOIN department d ON d.id = a.department_id
                 INNER JOIN indication_raw r ON r.id = a.indication_raw_id
                 WHERE a.import_id = :id
                 ORDER BY a.created_at, a.arrival_at, a.age, a.gender, d.public_id, r.public_id, a.urgency',
                ['id' => $importId],
            ),
            'mci' => $connection->fetchAllAssociative('SELECT mci_id, created_at, arrival_at FROM mci_case WHERE import_id = :id ORDER BY mci_id', ['id' => $importId]),
        ];
    }

    /**
     * Every upload day has the global KPI row of its imports, and no KPI row counts imports of another day.
     */
    private function assertKpisMatchTheUploads(string $message): void
    {
        $uploads = $this->connection()->fetchAllKeyValue('SELECT CAST(created_at AS date) AS day, SUM(row_count) FROM import GROUP BY 1 ORDER BY 1');
        $kpis = $this->connection()->fetchAllKeyValue('SELECT date, records_total FROM kpi_daily WHERE hospital_id IS NULL ORDER BY date');

        self::assertGreaterThan(1, \count($uploads), 'imports are spread over several upload days');
        self::assertEquals($uploads, $kpis, $message);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function describeOthers(int $importId): array|false
    {
        return $this->connection()->fetchAssociative(
            'SELECT COUNT(*) AS allocations, MIN(id) AS first_id, MAX(id) AS last_id,
                    md5(string_agg(concat_ws(\'|\', id, created_at, age, urgency), \',\' ORDER BY id)) AS checksum
             FROM allocation WHERE import_id <> :id',
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
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var SymfonyFixturesLoader $loader */
        $loader = $container->get('doctrine.fixtures.loader');
        /** @var ORMPurgerFactory $purgerFactory */
        $purgerFactory = $container->get('doctrine.fixtures.purger.orm_purger_factory');

        new ORMExecutor($entityManager, $purgerFactory->createForEntityManager('default', $entityManager, []))->execute($loader->getFixtures(['allocations']), false);
        $entityManager->clear();
    }

    private function hospital(int $id): Hospital
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $hospital = $entityManager->find(Hospital::class, $id);
        self::assertInstanceOf(Hospital::class, $hospital);

        return $hospital;
    }

    private function generator(): HospitalMonthGenerator
    {
        /** @var HospitalMonthGenerator $generator */
        $generator = self::getContainer()->get(HospitalMonthGenerator::class);

        return $generator;
    }

    private function volume(): FixtureVolume
    {
        /** @var FixtureVolumeResolver $resolver */
        $resolver = self::getContainer()->get(FixtureVolumeResolver::class);

        return $resolver->resolve();
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
