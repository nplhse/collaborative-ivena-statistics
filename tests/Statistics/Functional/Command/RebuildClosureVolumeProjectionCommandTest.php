<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Command;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use App\Statistics\UI\Console\Command\ClosureRebuildRequestsCommand;
use App\Statistics\UI\Console\Command\RebuildClosureVolumeProjectionCommand;
use App\Tests\Statistics\Support\RebuildsClosureAnalysis;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class RebuildClosureVolumeProjectionCommandTest extends KernelTestCase
{
    use Factories;
    use RebuildsClosureAnalysis;

    #[Test]
    public function commandRebuildsClosureVolumeProjection(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $seed = $this->seedClosureGraph($connection);
        $this->rebuildClosureAnalysis();
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($seed['importId']);

        /** @var RebuildClosureVolumeProjectionCommand $command */
        $command = self::getContainer()->get(RebuildClosureVolumeProjectionCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(0, $exitCode);

        self::assertGreaterThan(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_volume_hour'),
        );
        self::assertStringContainsString('Closure volume projection rebuilt', $tester->getDisplay());
    }

    #[Test]
    public function requestQueuedBeforeTheRunIsTakenWithoutASecondPass(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $seed = $this->seedClosureGraph($connection);
        $this->rebuildClosureAnalysis();
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($seed['importId']);
        // Queued like derive-export-periods --apply does; a rebuild that covers the hospital takes it before its first pass.
        $connection->insert('closure_rebuild_request', [
            'kind' => 'analysis',
            'hospital_id' => $seed['hospitalId'],
            'requested_at' => '2026-10-14 15:58:55',
        ]);

        /** @var RebuildClosureVolumeProjectionCommand $command */
        $command = self::getContainer()->get(RebuildClosureVolumeProjectionCommand::class);
        $tester = new CommandTester($command);
        $tester->execute(['--hospital' => [(string) $seed['hospitalId']]]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringNotContainsString('Pass 2', $display);
        self::assertStringContainsString('Closure volume projection rebuilt for 1 hospitals', $display);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_rebuild_request WHERE consumed_at IS NULL'));
        self::assertFalse($connection->createSchemaManager()->tablesExist(['closure_volume_hour_build']));

        $run = $connection->fetchAssociative("SELECT * FROM job_run WHERE job = 'closure_rebuild'");
        self::assertIsArray($run);
        $metrics = json_decode((string) $run['metrics'], true);
        self::assertIsArray($metrics);
        self::assertSame('console', $run['triggered_by']);
        self::assertSame('succeeded', $run['status']);
        self::assertSame([$seed['hospitalId']], $metrics['hospital_ids']);
        self::assertSame(1, $metrics['passes']);
        self::assertSame(1, $metrics['requests']);
        self::assertGreaterThan(0, $metrics['queries']);
        self::assertSame((int) $connection->fetchOne('SELECT COUNT(*) FROM closure_volume_hour'), $metrics['hours_written']);
    }

    #[Test]
    public function requestsCommandProcessesOpenRequestsLikeTheWorker(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $seed = $this->seedClosureGraph($connection);
        $this->rebuildClosureAnalysis();
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($seed['importId']);
        $connection->insert('closure_rebuild_request', [
            'kind' => 'volume',
            'hospital_id' => $seed['hospitalId'],
            'requested_at' => '2026-10-14 15:58:55',
            'range_from' => '2026-01-01 00:00:00',
            'range_to' => '2026-12-31 00:00:00',
        ]);

        $tester = new CommandTester(self::getContainer()->get(ClosureRebuildRequestsCommand::class));
        $tester->execute(['--run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Open requests processed', $tester->getDisplay());
        self::assertStringContainsString('No unconsumed requests.', $tester->getDisplay());
        self::assertSame('console', $connection->fetchOne("SELECT triggered_by FROM job_run WHERE job = 'closure_rebuild'"));
        self::assertGreaterThan(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_volume_hour WHERE hospital_id = ?', [$seed['hospitalId']]));
    }

    #[Test]
    public function requestArrivingDuringTheFirstPassRunsASecondPass(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $seed = $this->seedClosureGraph($connection);
        $this->rebuildClosureAnalysis();
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($seed['importId']);
        $passes = [];
        $queued = false;

        self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->rebuild(
            static function (int $done, int $total, int $pass) use ($connection, $seed, &$passes, &$queued): void {
                $passes[$pass] = true;
                if (!$queued && $done > 0) {
                    $queued = true;
                    $connection->insert('closure_rebuild_request', [
                        'kind' => 'analysis',
                        'hospital_id' => $seed['hospitalId'],
                        'requested_at' => '2026-10-14 15:58:55',
                    ]);
                }
            },
            [$seed['hospitalId']],
        );

        self::assertSame([1, 2], array_keys($passes));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_rebuild_request WHERE consumed_at IS NULL'));
        $metrics = json_decode((string) $connection->fetchOne("SELECT metrics FROM job_run WHERE job = 'closure_rebuild'"), true);
        self::assertIsArray($metrics);
        self::assertSame(2, $metrics['passes']);
        self::assertSame(2, $metrics['events'], 'One event in each pass.');
        self::assertSame(1, $metrics['requests']);
    }

    /**
     * @return array{importId: int, hospitalId: int}
     */
    private function seedClosureGraph(Connection $connection): array
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatch,
            'owner' => $user,
            'createdBy' => $user,
        ]);
        $speciality = SpecialityFactory::createOne();
        $department = DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne(['code' => random_int(900, 999)]);
        IndicationNormalizedFactory::createOne();
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $importId = (int) $import->getId();

        $connection->insert('closure_interval', [
            'hospital_id' => $hospital->getId(),
            'import_id' => $importId,
            'speciality_id' => $speciality->getId(),
            'department_id' => $department->getId(),
            'starts_at' => '2026-06-02 10:00:00',
            'ends_at' => '2026-06-02 11:00:00',
            'care_level' => 'emergency',
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => 'command volume unit',
            'source_group_id' => 'command-volume-group',
            'source_recorded_at' => '2026-06-02 09:00:00',
            'source_changed_at' => '2026-06-02 09:00:00',
        ]);

        foreach (['2026-05-05', '2026-05-12', '2026-05-19', '2026-05-26'] as $day) {
            AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatch,
                'speciality' => $speciality,
                'department' => $department,
                'createdAt' => new \DateTimeImmutable($day.' 10:10:00'),
                'arrivalAt' => new \DateTimeImmutable($day.' 10:30:00'),
                'urgency' => AllocationUrgency::EMERGENCY,
            ]);
        }
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatch,
            'speciality' => $speciality,
            'department' => $department,
            'createdAt' => new \DateTimeImmutable('2026-06-02 10:15:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-06-02 10:35:00'),
            'urgency' => AllocationUrgency::EMERGENCY,
        ]);

        return ['importId' => $importId, 'hospitalId' => (int) $hospital->getId()];
    }
}
