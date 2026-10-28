<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\Allocation\SyntheticAllocationGenerator;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Application\HospitalProfileResolver;
use App\Statistics\ClosureAnalytics\Infrastructure\Projection\ClosureAnalysisRebuilder;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class SyntheticAllocationGeneratorTest extends KernelTestCase
{
    private const string UNTIL = '2026-09-30';

    public function testTwoRunsGiveTheSameDataByContent(): void
    {
        // A fixed last day, so the result does not depend on the calendar day of the test run.
        $_ENV['FIXTURES_UNTIL'] = $_SERVER['FIXTURES_UNTIL'] = self::UNTIL;
        try {
            self::bootKernel();
            $this->load();
            $first = $this->describe();

            // Run the generator again on the same catalog and participation. A fresh kernel, like a new
            // `make reset`: the lookup cache of the sampler must not carry anything over.
            $this->deleteGeneratedRows();
            self::ensureKernelShutdown();
            self::bootKernel();
            /** @var ContainerInterface $container */
            $container = self::getContainer()->get('test.service_container'); // @phpstan-ignore symfonyContainer.serviceNotFound
            /** @var SyntheticAllocationGenerator $generator */
            $generator = $container->get(SyntheticAllocationGenerator::class);
            $generator->generate();
            $second = $this->describe();
        } finally {
            unset($_ENV['FIXTURES_UNTIL'], $_SERVER['FIXTURES_UNTIL']);
        }

        self::assertNotSame([], $first['allocations']);
        $last = max(array_map(static fn (array $row): string => (string) $row['last'], $first['summary']));
        self::assertStringStartsWith(self::UNTIL, $last, 'the period ends on the configured day');
        // Per hospital first, so a difference names the hospital and the period.
        self::assertSame($first['summary'], $second['summary']);
        self::assertSame(md5(serialize($first)), md5(serialize($second)), 'same seed and period, same rows (database ids aside)');
    }

    public function testMciCasesLieInTheMonthOfTheirImport(): void
    {
        self::bootKernel();
        $this->load();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        /** @var list<array{mci_id: string, created_at: string, arrival_at: string, import_name: string}> $cases */
        $cases = $connection->fetchAllAssociative(
            'SELECT m.mci_id, m.created_at, m.arrival_at, i.name AS import_name
             FROM mci_case m INNER JOIN import i ON i.id = m.import_id',
        );

        foreach ($cases as $case) {
            $month = substr($case['created_at'], 0, 7);
            self::assertStringEndsWith($month, $case['import_name'], $case['mci_id']);
            self::assertStringContainsString('-'.$month.'-', $case['mci_id']);
            self::assertGreaterThan($case['created_at'], $case['arrival_at']);
        }

        $this->assertCathLabCasesOnlyWhereTheProfileHasOne($connection);
        $this->assertClosedFlagOnlyDuringAClosure($connection);
        $this->assertProcessedLikeAnUpload($connection);

        $yesterday = (string) $connection->fetchOne("SELECT to_char(CURRENT_DATE - 1, 'YYYY-MM-DD')");
        self::assertLessThanOrEqual($yesterday.' 23:59:59', (string) $connection->fetchOne('SELECT MAX(created_at) FROM allocation'), 'the period ends yesterday');
    }

    /**
     * @return array{summary: list<array<string, mixed>>, allocations: list<array<string, mixed>>, closures: list<array<string, mixed>>, mci: list<array<string, mixed>>}
     */
    private function describe(): array
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        // Public ids of the synthetic catalog are name-based (ADR 020), database ids are not.
        return [
            'summary' => $connection->fetchAllAssociative(
                'SELECT h.public_id AS hospital, h.name, h.size, COUNT(a.id) AS allocations, MIN(a.created_at) AS first, MAX(a.created_at) AS last
                 FROM hospital h LEFT JOIN allocation a ON a.hospital_id = h.id
                 WHERE h.is_participating = true
                 GROUP BY h.public_id, h.name, h.size
                 ORDER BY h.public_id',
            ),
            'allocations' => $connection->fetchAllAssociative(
                'SELECT h.public_id AS hospital, i.name AS import, a.created_at, a.arrival_at, a.gender, a.age, a.urgency,
                        a.transport_type, a.requires_resus, a.requires_cathlab, a.is_cpr, a.is_ventilated, a.is_shock,
                        a.is_pregnant, a.is_with_physician, a.is_work_accident, a.department_was_closed,
                        d.public_id AS department, s.public_id AS speciality, r.public_id AS indication_raw
                 FROM allocation a
                 INNER JOIN hospital h ON h.id = a.hospital_id
                 INNER JOIN import i ON i.id = a.import_id
                 INNER JOIN department d ON d.id = a.department_id
                 INNER JOIN speciality s ON s.id = a.speciality_id
                 INNER JOIN indication_raw r ON r.id = a.indication_raw_id
                 ORDER BY h.public_id, a.created_at, a.arrival_at, a.age, a.gender, d.public_id, r.public_id, a.urgency',
            ),
            'closures' => $connection->fetchAllAssociative(
                'SELECT h.public_id AS hospital, c.starts_at, c.ends_at, c.care_level, c.reason, c.source_group_id, d.public_id AS department
                 FROM closure_interval c
                 INNER JOIN hospital h ON h.id = c.hospital_id
                 INNER JOIN department d ON d.id = c.department_id
                 ORDER BY h.public_id, c.starts_at, d.public_id',
            ),
            'mci' => $connection->fetchAllAssociative(
                'SELECT h.public_id AS hospital, m.mci_id, m.created_at, m.arrival_at
                 FROM mci_case m INNER JOIN hospital h ON h.id = m.hospital_id
                 ORDER BY m.mci_id',
            ),
        ];
    }

    /**
     * Checked on the same load as the MCI cases, so the profiles cost no extra fixture load.
     */
    private function assertCathLabCasesOnlyWhereTheProfileHasOne(Connection $connection): void
    {
        /** @var HospitalProfileResolver $profiles */
        $profiles = self::getContainer()->get(HospitalProfileResolver::class);
        /** @var FixtureVolumeResolver $volumes */
        $volumes = self::getContainer()->get(FixtureVolumeResolver::class);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $seed = $volumes->resolve()->seed;

        $withCathlab = 0;
        foreach ($connection->fetchAllAssociative('SELECT hospital_id, COUNT(*) FILTER (WHERE requires_cathlab) AS cathlab FROM allocation GROUP BY hospital_id') as $row) {
            $hospital = $entityManager->find(Hospital::class, (int) $row['hospital_id']);
            self::assertInstanceOf(Hospital::class, $hospital);
            if ($profiles->resolve($hospital, $seed)->hasCathlab) {
                ++$withCathlab;
                continue;
            }
            self::assertSame(0, (int) $row['cathlab'], sprintf('%s has no cath lab', (string) $hospital->getName()));
        }
        self::assertGreaterThan(0, $withCathlab, 'the full-care hospital has a cath lab');
    }

    /**
     * An allocation is flagged exactly when its department has a closure interval at its time.
     */
    private function assertClosedFlagOnlyDuringAClosure(Connection $connection): void
    {
        $rows = $connection->fetchAssociative(
            'SELECT COUNT(*) FILTER (WHERE a.department_was_closed AND c.id IS NULL) AS flagged_without_closure,
                    COUNT(*) FILTER (WHERE NOT a.department_was_closed AND c.id IS NOT NULL) AS closure_without_flag,
                    COUNT(*) FILTER (WHERE a.department_was_closed) AS flagged
             FROM allocation a
             LEFT JOIN LATERAL (
                 SELECT ci.id FROM closure_interval ci
                 WHERE ci.hospital_id = a.hospital_id AND ci.department_id = a.department_id
                   AND a.created_at >= ci.starts_at AND a.created_at < ci.ends_at
                 LIMIT 1
             ) c ON true',
        );
        self::assertIsArray($rows);
        self::assertSame(0, (int) $rows['flagged_without_closure']);
        self::assertSame(0, (int) $rows['closure_without_flag']);
        self::assertGreaterThan(0, (int) $rows['flagged'], 'some allocations reach a closed department');
    }

    /**
     * Every import has an export period and a data coverage, and the closure analysis finds all three event types.
     */
    private function assertProcessedLikeAnUpload(Connection $connection): void
    {
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM import WHERE export_starts_at IS NULL OR data_coverage IS NULL'));
        self::assertGreaterThan(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection'));

        /** @var ClosureAnalysisRebuilder $analysis */
        $analysis = self::getContainer()->get(ClosureAnalysisRebuilder::class);
        $analysis->rebuild();
        $types = $connection->fetchFirstColumn('SELECT DISTINCT event_type FROM closure_event ORDER BY event_type');
        self::assertSame(['cluster', 'single', 'source_group'], $types);
    }

    private function deleteGeneratedRows(): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        foreach (['mci_case', 'allocation_stats_projection', 'allocation', 'closure_interval', 'import'] as $table) {
            $connection->executeStatement(sprintf('DELETE FROM %s', $table));
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
}
