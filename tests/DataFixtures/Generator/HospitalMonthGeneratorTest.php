<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Application\HospitalMonthGenerator;
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

            $hospitalId = (int) $connection->fetchOne('SELECT hospital_id FROM import ORDER BY file_path LIMIT 1');
            $publicId = (string) $connection->fetchOne('SELECT public_id FROM hospital WHERE id = :id', ['id' => $hospitalId]);
            $marker = SyntheticImportMarker::likeAnySeed(SyntheticImportMarker::ALLOCATIONS, $publicId, self::MONTH);
            $importId = (int) $connection->fetchOne('SELECT id FROM import WHERE file_path LIKE :marker', ['marker' => $marker]);
            self::assertGreaterThan(0, $importId, 'the full load has an import for the month');

            $loaded = $this->describeMonth($importId);
            $others = $this->describeOthers($importId);
            $this->deleteImport($importId);

            $generator = $this->generator();
            $volume = $this->volume();
            $generated = $generator->generate($volume, $this->hospital($hospitalId), self::MONTH, $volume->lastDay(new MockClock()));

            self::assertSame($loaded, $this->describeMonth($generated->importId), 'same rows as inside the full load');
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
            $publicId = (string) $this->connection()->fetchOne('SELECT h.public_id FROM import i JOIN hospital h ON h.id = i.hospital_id ORDER BY i.file_path LIMIT 1');
            $tester = new CommandTester(new Application(self::$kernel)->find('app:generate:imports'));

            self::assertSame(Command::FAILURE, $tester->execute(['--hospital' => [$publicId], '--month' => self::MONTH]));
            self::assertStringContainsString('already has allocations', $tester->getDisplay());

            self::assertSame(Command::FAILURE, $tester->execute(['--hospital' => [$publicId], '--month' => '2026-10']));
            self::assertStringContainsString('lies after the last day', $tester->getDisplay());

            $imports = (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import');
            self::assertSame(Command::FAILURE, $tester->execute(['--hospital' => ['00000000-0000-0000-0000-000000000000'], '--month' => self::MONTH]));
            self::assertSame($imports, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import'), 'nothing was written');
        } finally {
            unset($_ENV['FIXTURES_UNTIL'], $_SERVER['FIXTURES_UNTIL']);
        }
    }

    /**
     * @return array{allocations: list<array<string, mixed>>, mci: list<array<string, mixed>>, import: array<string, mixed>|false}
     */
    private function describeMonth(int $importId): array
    {
        $connection = $this->connection();

        return [
            'import' => $connection->fetchAssociative('SELECT name, file_path, row_count, status FROM import WHERE id = :id', ['id' => $importId]),
            'allocations' => $connection->fetchAllAssociative(
                'SELECT a.created_at, a.arrival_at, a.gender, a.age, a.urgency, a.transport_type, a.requires_resus,
                        a.requires_cathlab, a.is_cpr, d.public_id AS department, r.public_id AS indication_raw
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
        foreach (['allocation_stats_projection WHERE import_id', 'mci_case WHERE import_id', 'allocation WHERE import_id', 'import WHERE id'] as $where) {
            $this->connection()->executeStatement(sprintf('DELETE FROM %s = :id', $where), ['id' => $importId]);
        }
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
