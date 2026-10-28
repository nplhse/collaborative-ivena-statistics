<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Application\Scenario\PresetScenarioPlanner;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Presets lay their scenarios over the data, placed with the seed (#865). One `medium` load.
 */
#[ResetDatabase]
final class PresetScenarioTest extends KernelTestCase
{
    private const string UNTIL = '2026-02-28';

    public function testScenarioCountsFollowThePeriod(): void
    {
        $volume = static fn (int $months): FixtureVolume => new FixtureVolume('test', [], $months, [], 1.0, 0.0, 740, false, scenarios: ['mci' => 1.0, 'icy-road' => 0.5, 'heat' => 0.0]);

        self::assertSame(1, $volume(12)->scenarioCount('mci'));
        self::assertSame(2, $volume(24)->scenarioCount('mci'));
        self::assertSame(1, $volume(1)->scenarioCount('mci'), 'at least once');
        self::assertSame(1, $volume(24)->scenarioCount('icy-road'));
        self::assertSame(0, $volume(24)->scenarioCount('heat'));
        self::assertSame(0, $volume(24)->scenarioCount('flu-wave'));
    }

    public function testTheMediumPresetSpreadsAnMciOverSeveralHospitals(): void
    {
        $_ENV['FIXTURES_UNTIL'] = $_SERVER['FIXTURES_UNTIL'] = self::UNTIL;
        $_ENV['FIXTURES_PROFILE'] = $_SERVER['FIXTURES_PROFILE'] = 'medium';
        try {
            self::bootKernel();
            $this->load();

            $mci = $this->connection()->fetchAssociative(
                "SELECT COUNT(DISTINCT m.hospital_id) AS hospitals, COUNT(DISTINCT m.mci_id) AS ids, COUNT(*) AS patients
                 FROM mci_case m JOIN import i ON i.id = m.import_id WHERE i.file_path LIKE 'synthetic/scenario/mci/%'",
            );
            self::assertIsArray($mci);
            self::assertGreaterThanOrEqual(2, (int) $mci['hospitals'], 'the MCI takes several hospitals');
            self::assertLessThanOrEqual(4, (int) $mci['hospitals']);
            self::assertSame(1, (int) $mci['ids'], 'one incident');
            self::assertGreaterThanOrEqual(8, (int) $mci['patients']);
            self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM import WHERE file_path LIKE 'synthetic/scenario/%' AND file_path NOT LIKE 'synthetic/scenario/mci/%'"), 'medium has only the MCI');

            // Scenario rows are in the projection and the KPIs like base data.
            self::assertSame(
                (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM allocation'),
                (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection'),
            );
            self::assertSame(
                (int) $this->connection()->fetchOne('SELECT SUM(row_count) FROM import'),
                (int) $this->connection()->fetchOne('SELECT SUM(records_total) FROM kpi_daily WHERE hospital_id IS NULL'),
            );

            // Same seed, same placement.
            /** @var PresetScenarioPlanner $planner */
            $planner = self::getContainer()->get(PresetScenarioPlanner::class);
            /** @var FixtureVolumeResolver $resolver */
            $resolver = self::getContainer()->get(FixtureVolumeResolver::class);
            $volume = $resolver->resolve();
            $hospitals = $this->activeHospitals();
            $first = new \DateTimeImmutable('2026-01-01');
            $last = new \DateTimeImmutable(self::UNTIL);
            self::assertEquals($planner->plan($volume, $hospitals, $first, $last), $planner->plan($volume, array_reverse($hospitals), $first, $last));
            self::assertNotEquals($planner->plan($volume, $hospitals, $first, $last), $planner->plan($volume->withSeed(741), $hospitals, $first, $last));
        } finally {
            unset($_ENV['FIXTURES_UNTIL'], $_SERVER['FIXTURES_UNTIL'], $_ENV['FIXTURES_PROFILE'], $_SERVER['FIXTURES_PROFILE']);
        }
    }

    /**
     * @return list<Hospital>
     */
    private function activeHospitals(): array
    {
        /** @var list<int> $ids */
        $ids = array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT DISTINCT hospital_id FROM allocation ORDER BY hospital_id'));

        return array_map(function (int $id): Hospital {
            $hospital = $this->entityManager()->find(Hospital::class, $id);
            self::assertInstanceOf(Hospital::class, $hospital);

            return $hospital;
        }, $ids);
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
