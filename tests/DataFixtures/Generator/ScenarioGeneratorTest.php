<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Application\Scenario\ScenarioCatalog;
use App\DataFixtures\Generator\Application\Scenario\ScenarioGenerator;
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
 * Scenarios only add rows, are refused twice and follow their seed (ADR 022). One fixture load for all checks.
 */
#[ResetDatabase]
final class ScenarioGeneratorTest extends KernelTestCase
{
    private const string UNTIL = '2026-09-30';

    public function testScenariosAddOnlyTheirOwnRows(): void
    {
        $_ENV['FIXTURES_UNTIL'] = $_SERVER['FIXTURES_UNTIL'] = self::UNTIL;
        try {
            self::bootKernel();
            $this->load();
            $hospitals = [$this->activeHospital()];
            $volume = $this->volume();
            $lastDay = $volume->lastDay(new MockClock());
            $generator = $this->generator();
            $base = $this->baseChecksum();

            // Icy-road: exactly the planned allocations, in their own import, base data untouched.
            $icyRoad = ScenarioCatalog::get('icy-road');
            $plan = $generator->plan($icyRoad, $volume, $hospitals, new \DateTimeImmutable('2026-01-14'), $lastDay, 'test');
            self::assertEquals($plan, $generator->plan($icyRoad, $volume, $hospitals, new \DateTimeImmutable('2026-01-14'), $lastDay, 'test'), 'same seed, same plan');
            [$result] = $generator->apply($icyRoad, $volume, $this->reload($hospitals), new \DateTimeImmutable('2026-01-14'), $lastDay, 'test');
            self::assertSame($plan[0]->allocationCount(), $result->allocations);
            self::assertSame($result->allocations, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM allocation WHERE import_id = :id', ['id' => $result->importId]));
            self::assertSame($result->allocations, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection WHERE import_id = :id', ['id' => $result->importId]), 'the overlay goes through the projection');
            self::assertSame($base, $this->baseChecksum(), 'base allocations are left alone');

            // The overlay is uploaded after its day, and the KPIs of that day count it.
            $uploadDay = (string) $this->connection()->fetchOne('SELECT CAST(created_at AS date) FROM import WHERE id = :id', ['id' => $result->importId]);
            self::assertContains($uploadDay, ['2026-01-15', '2026-01-16', '2026-01-17']);
            self::assertSame(
                (int) $this->connection()->fetchOne('SELECT SUM(row_count) FROM import WHERE CAST(created_at AS date) = :day', ['day' => $uploadDay]),
                (int) $this->connection()->fetchOne('SELECT records_total FROM kpi_daily WHERE hospital_id IS NULL AND date = :day', ['day' => $uploadDay]),
            );

            // Flu wave: extra closures in their own import; flagged allocations only during them.
            [$flu] = $generator->apply(ScenarioCatalog::get('flu-wave'), $volume, $this->reload($hospitals), new \DateTimeImmutable('2026-02-02'), $lastDay, 'test');
            self::assertGreaterThan(0, $flu->closures);
            self::assertSame(0, (int) $this->connection()->fetchOne(
                'SELECT COUNT(*) FROM allocation a WHERE a.import_id = :id AND a.department_was_closed
                 AND NOT EXISTS (SELECT 1 FROM closure_interval c WHERE c.hospital_id = a.hospital_id AND c.department_id = a.department_id AND a.created_at >= c.starts_at AND a.created_at < c.ends_at)',
                ['id' => $flu->importId],
            ));

            // MCI: patients with urgency 1, resuscitation room and physician, one MCI id, within 90 minutes.
            [$mci] = $generator->apply(ScenarioCatalog::get('mci'), $volume, $this->reload($hospitals), new \DateTimeImmutable('2026-03-05'), $lastDay, 'test');
            $patients = $this->connection()->fetchAssociative(
                'SELECT COUNT(*) AS patients, COUNT(DISTINCT mci_id) AS ids, bool_and(urgency = 1 AND requires_resus AND is_with_physician) AS urgent,
                        EXTRACT(EPOCH FROM MAX(created_at) - MIN(created_at)) / 60 AS minutes
                 FROM mci_case WHERE import_id = :id',
                ['id' => $mci->importId],
            );
            self::assertIsArray($patients);
            self::assertGreaterThanOrEqual(8, (int) $patients['patients'], 'a single hospital gets every patient');
            self::assertSame(1, (int) $patients['ids']);
            self::assertTrue((bool) $patients['urgent']);
            self::assertLessThanOrEqual(90, (float) $patients['minutes']);
            self::assertSame($base, $this->baseChecksum(), 'base allocations are still left alone');

            // A second application is refused, also through the command.
            $publicId = $hospitals[0]->getPublicIdString();
            $tester = new CommandTester(new Application(self::$kernel)->find('app:generate:scenario'));
            self::assertSame(Command::FAILURE, $tester->execute(['scenario' => 'icy-road', '--hospital' => [$publicId], '--at' => '2026-01-14']));
            self::assertStringContainsString('already has the scenario', $tester->getDisplay());
            self::assertSame(Command::SUCCESS, $tester->execute(['scenario' => 'heat', '--hospital' => [$publicId], '--at' => '2026-07-20', '--dry-run' => true]));
        } finally {
            unset($_ENV['FIXTURES_UNTIL'], $_SERVER['FIXTURES_UNTIL']);
        }
    }

    private function baseChecksum(): string
    {
        return (string) $this->connection()->fetchOne(
            "SELECT md5(string_agg(concat_ws('|', a.id, a.created_at, a.department_was_closed), ',' ORDER BY a.id))
             FROM allocation a JOIN import i ON i.id = a.import_id WHERE i.file_path NOT LIKE 'synthetic/scenario/%'",
        );
    }

    private function activeHospital(): Hospital
    {
        $id = (int) $this->connection()->fetchOne('SELECT hospital_id FROM allocation GROUP BY hospital_id ORDER BY COUNT(*) DESC LIMIT 1');

        return $this->reload([$this->entityManager()->getReference(Hospital::class, $id)])[0];
    }

    /**
     * @param list<Hospital> $hospitals
     *
     * @return list<Hospital>
     */
    private function reload(array $hospitals): array
    {
        $ids = array_map(static fn (Hospital $hospital): int => (int) $hospital->getId(), $hospitals);
        $this->entityManager()->clear();

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

    private function generator(): ScenarioGenerator
    {
        /** @var ScenarioGenerator $generator */
        $generator = self::getContainer()->get(ScenarioGenerator::class);

        return $generator;
    }

    private function volume(): FixtureVolume
    {
        /** @var FixtureVolumeResolver $resolver */
        $resolver = self::getContainer()->get(FixtureVolumeResolver::class);

        return $resolver->resolve();
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
