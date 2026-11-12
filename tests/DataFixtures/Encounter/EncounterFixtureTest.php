<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Encounter;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\Encounter\Application\SyntheticVisit;
use App\DataFixtures\Encounter\Infrastructure\SyntheticEncounterLoader;
use App\DataFixtures\FixtureVolumeResolver;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\Encounter\Domain\Enum\EncounterGrantAction;
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
 * The `encounters` fixture group on the synthetic allocations of the test preset (#1052): the demo
 * hospital of `foo` gets one encounter import for each of the last three months of the period (the
 * test preset has two: August and September 2026), built from its allocations; no other hospital gets
 * encounter data; `foo` gets the pilot role and an encounter grant for it; the files command writes
 * the same visits.
 */
#[ResetDatabase]
final class EncounterFixtureTest extends KernelTestCase
{
    private const string UNTIL = '2026-09-30';

    public function testDemoHospitalGetsTheVisitsOfItsAllocationsForThreeMonths(): void
    {
        $_ENV['FIXTURES_UNTIL'] = $_SERVER['FIXTURES_UNTIL'] = self::UNTIL;
        $directory = sys_get_temp_dir().'/encounter-files-'.bin2hex(random_bytes(4));
        try {
            self::bootKernel();
            $this->load();
            $connection = $this->connection();
            $hospitalId = (int) $connection->fetchOne("SELECT h.id FROM hospital h JOIN \"user\" u ON u.id = h.owner_id WHERE u.username = 'foo'");
            $hospital = $this->entityManager()->find(Hospital::class, $hospitalId);
            self::assertInstanceOf(Hospital::class, $hospital);

            $imports = $connection->fetchAllAssociative('SELECT i.id, i.file_path, i.type, ei.inserted FROM import i JOIN encounter_import ei ON ei.import_id = i.id ORDER BY i.file_path');
            $seed = $this->seed();
            $months = \array_slice(['2026-07', '2026-08', '2026-09'], -min(3, $this->months() + 1));
            self::assertSame(
                array_map(static fn (string $month): string => sprintf('synthetic/encounters/seed-%d/%s/%s.csv', $seed, $hospital->getPublicId(), $month), $months),
                array_column($imports, 'file_path'),
            );
            self::assertSame([$hospitalId], array_map(intval(...), $connection->fetchFirstColumn('SELECT DISTINCT hospital_id FROM encounter')));

            // foo owns the hospital and gets the pilot role and an encounter grant for it, and nothing else.
            $foo = $this->entityManager()->getRepository(User::class)->findOneBy(['username' => 'foo']);
            self::assertInstanceOf(User::class, $foo);
            self::assertSame((int) $foo->getId(), (int) $hospital->getOwner()?->getId(), 'foo has hospital access as owner');
            self::assertContains(UserRole::ENCOUNTER_BETA, $foo->getRoles());
            $grants = $this->entityManager()->getRepository(EncounterAccessGrant::class)->findBy(['user' => $foo]);
            self::assertCount(1, $grants);
            self::assertSame($hospitalId, (int) $grants[0]->getHospital()->getId());
            self::assertSame([EncounterGrantAction::Upload, EncounterGrantAction::Review, EncounterGrantAction::ViewCases], $grants[0]->getActions());
            $admin = $this->entityManager()->getRepository(User::class)->findOneBy(['username' => 'admin']);
            self::assertInstanceOf(User::class, $admin);
            self::assertNotContains(UserRole::ENCOUNTER_BETA, $admin->getRoles(), 'admin inherits the role instead');

            // Same seed, same visits: the loader generates exactly what the fixture stored.
            $loader = self::getContainer()->get(SyntheticEncounterLoader::class);
            self::assertInstanceOf(SyntheticEncounterLoader::class, $loader);
            foreach ($months as $index => $month) {
                $generated = $loader->generate($hospital, $seed, $month, new \DateTimeImmutable(self::UNTIL));
                $allocations = (int) $connection->fetchOne(
                    'SELECT COUNT(*) FROM allocation WHERE hospital_id = ? AND arrival_at >= ? AND arrival_at < ?',
                    [$hospitalId, $month.'-01', new \DateTimeImmutable($month.'-01')->modify('+1 month')->format('Y-m-d')],
                );
                self::assertGreaterThan(0, $allocations);
                self::assertSame((int) round($allocations * 0.85), $generated->countsByKind()['linked']);
                self::assertSame(\count($generated->visits), (int) $imports[$index]['inserted']);
                self::assertSame(
                    array_map(static fn (SyntheticVisit $visit): string => $visit->matchingAnchor()->format('Y-m-d H:i').'|'.$visit->cells['age'].'|'.$visit->cells['sex'], $generated->visits),
                    $connection->fetchFirstColumn(
                        "SELECT to_char(COALESCE(ed_admission_at, first_assessment_contact_at), 'YYYY-MM-DD HH24:MI') || '|' || age || '|' || sex FROM encounter WHERE import_id = ? ORDER BY COALESCE(ed_admission_at, first_assessment_contact_at), id",
                        [$imports[$index]['id']],
                    ),
                );
            }

            $tester = new CommandTester(new Application(self::$kernel)->find('app:generate:encounter-files'));
            self::assertSame(Command::SUCCESS, $tester->execute(['--hospital' => (string) $hospital->getPublicId(), '--month' => '2026-09', '--dir' => $directory]), $tester->getDisplay());
            self::assertFileExists($directory.'/encounter-key.json');
            self::assertFileExists($directory.'/ed_visit_synthetic_2026-09_part1.csv');
            self::assertFileExists($directory.'/ed_visit_synthetic_2026-09_part2.csv');
            /** @var array{visits: list<array<string, mixed>>} $truth */
            $truth = json_decode((string) file_get_contents($directory.'/truth.json'), true, flags: \JSON_THROW_ON_ERROR);
            self::assertCount((int) $imports[\count($months) - 1]['inserted'], $truth['visits']);
            self::assertSame(Command::INVALID, $tester->execute(['--hospital' => (string) $hospital->getPublicId(), '--month' => '2026-10', '--dir' => $directory]));
        } finally {
            unset($_ENV['FIXTURES_UNTIL'], $_SERVER['FIXTURES_UNTIL']);
            new Filesystem()->remove($directory);
        }
    }

    private function seed(): int
    {
        return $this->resolver()->resolve()->seed;
    }

    private function months(): int
    {
        return $this->resolver()->resolve()->months;
    }

    private function resolver(): FixtureVolumeResolver
    {
        $resolver = self::getContainer()->get(FixtureVolumeResolver::class);
        self::assertInstanceOf(FixtureVolumeResolver::class, $resolver);

        return $resolver;
    }

    private function load(): void
    {
        /** @var ContainerInterface $container */
        $container = self::getContainer()->get('test.service_container'); // @phpstan-ignore symfonyContainer.serviceNotFound
        /** @var SymfonyFixturesLoader $loader */
        $loader = $container->get('doctrine.fixtures.loader');
        /** @var ORMPurgerFactory $purgerFactory */
        $purgerFactory = $container->get('doctrine.fixtures.purger.orm_purger_factory');

        new ORMExecutor($this->entityManager(), $purgerFactory->createForEntityManager('default', $this->entityManager(), []))->execute($loader->getFixtures(['encounters']), false);
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
