<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator\Run;

use App\DataFixtures\Generator\Application\SyntheticImportMarker;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * A tracked run goes through the same projections as the synchronous path (#791, ADR 023). In tests the
 * background messages are handled at once, so the run is finished when the command returns.
 */
#[ResetDatabase]
final class GenerationRunPipelineTest extends KernelTestCase
{
    private const string UNTIL = '2026-09-30';

    private const string MONTH = '2026-08';

    public function testATrackedRunProjectsTheHospitalAndNotifiesItsOwner(): void
    {
        $_ENV['FIXTURES_UNTIL'] = $_SERVER['FIXTURES_UNTIL'] = self::UNTIL;
        try {
            self::bootKernel();
            $this->load();
            [$hospitalId, $publicId, $ownerId] = $this->hospitalWithoutTheMonth();

            $tester = $this->command('app:generate:imports');
            self::assertSame(Command::INVALID, $tester->execute(['--hospital' => [$publicId], '--month' => self::MONTH, '--notify' => true]), '--notify needs --async');
            self::assertSame(Command::SUCCESS, $tester->execute(['--hospital' => [$publicId], '--month' => self::MONTH, '--async' => true, '--notify' => true]), $tester->getDisplay());
            self::assertStringContainsString('app:generate:run:show', $tester->getDisplay());

            $run = $this->connection()->fetchAssociative('SELECT id, status, stage, scope, seed, notify, reason_code FROM generation_run');
            self::assertIsArray($run);
            self::assertSame(['completed', 'done', 'one', true, null], [$run['status'], $run['stage'], $run['scope'], $run['notify'], $run['reason_code']]);

            $importId = (int) $this->connection()->fetchOne('SELECT id FROM import WHERE file_path LIKE :marker', ['marker' => SyntheticImportMarker::likeAnySeed(SyntheticImportMarker::ALLOCATIONS, $publicId, self::MONTH)]);
            $allocations = (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM allocation WHERE import_id = :id', ['id' => $importId]);
            $hospital = $this->connection()->fetchAssociative('SELECT status, months, allocations, allocation_import_ids, projection_attempts FROM generation_run_hospital WHERE run_id = :run', ['run' => $run['id']]);
            self::assertIsArray($hospital);
            self::assertSame('completed', $hospital['status']);
            self::assertSame(1, $hospital['months']);
            self::assertSame($allocations, $hospital['allocations'], 'the run counts what was written');
            self::assertSame('['.$importId.']', $hospital['allocation_import_ids']);
            self::assertSame(1, $hospital['projection_attempts']);

            // The same projections as the synchronous path: one projection row per allocation, closure events.
            self::assertSame($allocations, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection WHERE import_id = :id', ['id' => $importId]));
            self::assertGreaterThan(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM closure_event WHERE hospital_id = :id', ['id' => $hospitalId]));
            $this->assertKpisMatchTheUploads();

            $notification = $this->connection()->fetchAssociative("SELECT recipient_id, scope_reference_id, parameters FROM notification WHERE type = 'generation.completed'");
            self::assertIsArray($notification);
            self::assertSame([$ownerId, $hospitalId], [$notification['recipient_id'], $notification['scope_reference_id']]);
            self::assertStringContainsString('"months":1', (string) $notification['parameters']);

            $show = $this->command('app:generate:run:show');
            self::assertSame(Command::SUCCESS, $show->execute([]));
            self::assertStringContainsString('completed', $show->getDisplay());
            self::assertStringContainsString((string) $importId, $show->getDisplay());
            $list = $this->command('app:generate:run:list');
            self::assertSame(Command::SUCCESS, $list->execute([]));
            self::assertStringContainsString((string) $run['id'], $list->getDisplay());
        } finally {
            unset($_ENV['FIXTURES_UNTIL'], $_SERVER['FIXTURES_UNTIL']);
        }
    }

    public function testWithoutAsyncTheCommandStaysSynchronousAndTracksNothing(): void
    {
        $_ENV['FIXTURES_UNTIL'] = $_SERVER['FIXTURES_UNTIL'] = self::UNTIL;
        try {
            self::bootKernel();
            $this->load();
            [, $publicId] = $this->hospitalWithoutTheMonth();

            $tester = $this->command('app:generate:imports');
            self::assertSame(Command::SUCCESS, $tester->execute(['--hospital' => [$publicId], '--month' => self::MONTH]), $tester->getDisplay());

            self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM generation_run'));
            self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM notification WHERE type = 'generation.completed'"));
            $importId = (int) $this->connection()->fetchOne('SELECT id FROM import WHERE file_path LIKE :marker', ['marker' => SyntheticImportMarker::likeAnySeed(SyntheticImportMarker::ALLOCATIONS, $publicId, self::MONTH)]);
            self::assertGreaterThan(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection WHERE import_id = :id', ['id' => $importId]));
            self::assertSame(Command::FAILURE, $this->command('app:generate:run:show')->execute([]), 'no run to show');
        } finally {
            unset($_ENV['FIXTURES_UNTIL'], $_SERVER['FIXTURES_UNTIL']);
        }
    }

    /**
     * A hospital of the full load with the month removed, so the command can generate it again.
     *
     * @return array{0: int, 1: string, 2: int}
     */
    private function hospitalWithoutTheMonth(): array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT h.id, h.public_id, h.owner_id FROM import i JOIN hospital h ON h.id = i.hospital_id
             WHERE i.file_path LIKE 'synthetic/allocations/%' AND h.owner_id IS NOT NULL ORDER BY i.file_path LIMIT 1",
        );
        self::assertIsArray($row);
        $publicId = (string) $row['public_id'];
        foreach ([SyntheticImportMarker::ALLOCATIONS, SyntheticImportMarker::CLOSURES] as $type) {
            $importId = (int) $this->connection()->fetchOne('SELECT id FROM import WHERE file_path LIKE :marker', ['marker' => SyntheticImportMarker::likeAnySeed($type, $publicId, self::MONTH)]);
            foreach (['allocation_stats_projection WHERE import_id', 'mci_case WHERE import_id', 'allocation WHERE import_id', 'closure_interval WHERE import_id', 'import WHERE id'] as $where) {
                $this->connection()->executeStatement(\sprintf('DELETE FROM %s = :id', $where), ['id' => $importId]);
            }
        }

        return [(int) $row['id'], $publicId, (int) $row['owner_id']];
    }

    private function assertKpisMatchTheUploads(): void
    {
        $uploads = $this->connection()->fetchAllKeyValue('SELECT CAST(created_at AS date) AS day, SUM(row_count) FROM import GROUP BY 1 ORDER BY 1');
        $kpis = $this->connection()->fetchAllKeyValue('SELECT date, records_total FROM kpi_daily WHERE hospital_id IS NULL ORDER BY date');

        self::assertEquals($uploads, $kpis, 'the final stage aggregated the KPIs of the new upload day');
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester(new Application(self::$kernel)->find($name));
    }

    private function load(): void
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var SymfonyFixturesLoader $loader */
        $loader = $container->get('doctrine.fixtures.loader');
        /** @var ORMPurgerFactory $purgerFactory */
        $purgerFactory = $container->get('doctrine.fixtures.purger.orm_purger_factory');

        new ORMExecutor($entityManager, $purgerFactory->createForEntityManager('default', $entityManager, []))->execute($loader->getFixtures(['allocations']), false);
        $entityManager->clear();
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
