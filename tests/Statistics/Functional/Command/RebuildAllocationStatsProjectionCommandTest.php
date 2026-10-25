<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Command;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationProjectionRunTelemetryInterface;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Contract\MaterializedViewRefresherInterface;
use App\Statistics\UI\Console\Command\RebuildAllocationStatsProjectionCommand;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class RebuildAllocationStatsProjectionCommandTest extends KernelTestCase
{
    use Factories;

    #[Test]
    public function commandTruncatesAndRebuildsProjectionFromAllocations(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $seed = $this->seedReferenceGraph();
        $importA = ImportFactory::createOne([
            'name' => 'Projection Seed Import A',
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
        ]);
        $importB = ImportFactory::createOne([
            'name' => 'Projection Seed Import B',
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
        ]);

        AllocationFactory::createOne($this->allocationDefaults($seed, $importA, 30, '2025-03-01 08:00:00', '2025-03-01 08:30:00'));
        AllocationFactory::createOne($this->allocationDefaults($seed, $importB, 40, '2025-03-02 08:00:00', '2025-03-02 09:15:00'));

        $connection->executeStatement(
            'INSERT INTO allocation_stats_projection (id, import_id, hospital_id, state_id, dispatch_area_id, speciality_id, department_id, occasion_id, assignment_id, infection_id, indication_normalized_id, created_at, arrival_at, created_year, created_quarter, created_month, created_week, created_day, created_weekday, created_hour, day_time_bucket_code, shift_bucket_code, transport_time_minutes, age, gender_code, urgency_code, transport_type_code, requires_resus, requires_cathlab, is_cpr, is_ventilated, is_work_accident, is_with_physician) VALUES (999999, 999999, 1, 1, 1, 1, 1, NULL, 1, NULL, NULL, NOW(), NOW(), 2025, 1, 1, 1, 1, 1, 1, 1, 1, 10, 10, 1, 1, 1, false, false, false, false, false, false)',
        );

        /** @var RebuildAllocationStatsProjectionCommand $command */
        $command = self::getContainer()->get(RebuildAllocationStatsProjectionCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(0, $exitCode);

        $allocationCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation');
        $projectionCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection');
        self::assertSame($allocationCount, $projectionCount, 'Projection row count must match allocation row count.');

        $legacyCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection WHERE id = 999999');
        self::assertSame(0, $legacyCount, 'Pre-existing stale projection row must be removed by truncate.');

        $display = $tester->getDisplay();
        self::assertStringContainsString('Projection table truncated.', $display);
        self::assertStringContainsString('Refreshing materialized views', $display);
        self::assertStringContainsString('Projection rebuild finished. Imports processed: 2', $display);

        $run = $connection->fetchAssociative("SELECT * FROM job_run WHERE job = 'allocation_projection_full_rebuild'");
        self::assertIsArray($run, 'A console rebuild of every import is one run.');
        self::assertSame('console', $run['triggered_by']);
        self::assertSame('succeeded', $run['status']);
        self::assertNull($run['import_id']);
        $metrics = json_decode((string) $run['metrics'], true);
        self::assertIsArray($metrics);
        self::assertSame(2, $metrics['imports']);
        self::assertSame($projectionCount, $metrics['rows_written']);
        self::assertSame(0, $metrics['rows_deleted'], 'The table was truncated before.');
        self::assertTrue($metrics['mv_refreshed']);
        foreach (['truncate_ms', 'rebuild_imports_ms', 'delete_ms', 'insert_ms', 'mv_refresh_ms'] as $step) {
            self::assertIsInt($metrics[$step], $step);
        }
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM job_run WHERE job = 'allocation_projection_rebuild'"), 'No run per import.');
    }

    #[Test]
    public function commandSucceedsWhenNoAllocationsExist(): void
    {
        self::bootKernel();

        /** @var RebuildAllocationStatsProjectionCommand $command */
        $command = self::getContainer()->get(RebuildAllocationStatsProjectionCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Projection table remains empty. Materialized views refreshed.', $tester->getDisplay());

        $run = self::getContainer()->get(Connection::class)->fetchAssociative("SELECT status, metrics FROM job_run WHERE job = 'allocation_projection_full_rebuild'");
        self::assertIsArray($run);
        self::assertSame('succeeded', $run['status']);
        $metrics = json_decode((string) $run['metrics'], true);
        self::assertIsArray($metrics);
        self::assertArrayNotHasKey('imports', $metrics);
        self::assertTrue($metrics['mv_refreshed']);
    }

    #[Test]
    public function aFailedMaterializedViewRefreshFailsTheRun(): void
    {
        self::bootKernel();
        $refresher = $this->createStub(MaterializedViewRefresherInterface::class);
        $refresher->method('refresh')->willReturn([['view' => 'mv_overview', 'success' => true, 'message' => 'Refreshed.'], ['view' => 'mv_state', 'success' => false, 'message' => 'Refresh failed.']]);
        $this->seedProjectionImport();

        $tester = new CommandTester($this->command(refresher: $refresher));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Refresh failed.', $tester->getDisplay());
        $run = self::getContainer()->get(Connection::class)->fetchAssociative("SELECT status, error_class FROM job_run WHERE job = 'allocation_projection_full_rebuild'");
        self::assertSame(['status' => 'failed', 'error_class' => \RuntimeException::class], $run);
    }

    #[Test]
    public function aRebuildErrorFailsTheRunWithItsError(): void
    {
        self::bootKernel();
        $rebuilder = $this->createStub(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->method('rebuildForImport')->willThrowException(new \LogicException('rebuild broke'));
        $this->seedProjectionImport();

        $tester = new CommandTester($this->command(rebuilder: $rebuilder));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Projection rebuild failed: rebuild broke', $tester->getDisplay());
        $run = self::getContainer()->get(Connection::class)->fetchAssociative("SELECT status, error_class, error_message FROM job_run WHERE job = 'allocation_projection_full_rebuild'");
        self::assertSame(['status' => 'failed', 'error_class' => \LogicException::class, 'error_message' => 'rebuild broke'], $run);
    }

    private function seedProjectionImport(): void
    {
        $seed = $this->seedReferenceGraph();
        $import = ImportFactory::createOne([
            'name' => 'Projection Failure Import',
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
        ]);
        AllocationFactory::createOne($this->allocationDefaults($seed, $import, 30, '2025-03-01 08:00:00', '2025-03-01 08:30:00'));
    }

    private function command(
        ?AllocationStatsProjectionRebuildInterface $rebuilder = null,
        ?MaterializedViewRefresherInterface $refresher = null,
    ): RebuildAllocationStatsProjectionCommand {
        $container = self::getContainer();

        return new RebuildAllocationStatsProjectionCommand(
            $container->get(Connection::class),
            $container->get(EntityManagerInterface::class),
            $rebuilder ?? $container->get(AllocationStatsProjectionRebuildInterface::class),
            $refresher ?? $container->get(MaterializedViewRefresherInterface::class),
            $container->get(AllocationProjectionRunTelemetryInterface::class),
        );
    }

    #[Test]
    public function commandRunsGarbageCollectionAfterTwentyFiveImports(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $seed = $this->seedReferenceGraph();

        for ($i = 0; $i < 25; ++$i) {
            $import = ImportFactory::createOne([
                'name' => 'GC Projection Import '.$i,
                'hospital' => $seed['hospital'],
                'createdBy' => $seed['user'],
            ]);
            AllocationFactory::createOne($this->allocationDefaults(
                $seed,
                $import,
                20 + $i,
                '2025-04-01 08:00:00',
                '2025-04-01 08:30:00',
            ));
        }

        /** @var RebuildAllocationStatsProjectionCommand $command */
        $command = self::getContainer()->get(RebuildAllocationStatsProjectionCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(0, $exitCode);

        $allocationCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation');
        $projectionCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection');
        self::assertSame($allocationCount, $projectionCount);
        self::assertStringContainsString('Projection rebuild finished. Imports processed: 25', $tester->getDisplay());
    }

    /**
     * @return array{
     *     user: object,
     *     state: object,
     *     dispatchArea: object,
     *     hospital: object,
     *     indicationNormalized: object
     * }
     */
    private function seedReferenceGraph(): array
    {
        $user = UserFactory::createOne(['username' => 'seed-proj-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'SeedProjectionState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'SeedProjectionDispatchArea', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'SeedProjectionHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        SpecialityFactory::createOne(['name' => 'SeedProjectionSpeciality']);
        DepartmentFactory::createOne(['name' => 'SeedProjectionDepartment']);
        AssignmentFactory::createOne(['name' => 'SeedProjectionAssignment']);
        OccasionFactory::createOne(['name' => 'SeedProjectionOccasion']);
        SecondaryTransportFactory::createOne(['name' => 'SeedProjectionSecondaryTransport']);
        InfectionFactory::createOne(['name' => 'SeedProjectionInfection']);
        IndicationRawFactory::createOne(['name' => 'SeedProjectionRawIndication', 'code' => 700001]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'SeedProjectionNormalizedIndication']);

        return [
            'user' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'hospital' => $hospital,
            'indicationNormalized' => $indicationNormalized,
        ];
    }

    /**
     * @param array{
     *     user: object,
     *     state: object,
     *     dispatchArea: object,
     *     hospital: object,
     *     indicationNormalized: object
     * } $seed
     *
     * @return array<string, mixed>
     */
    private function allocationDefaults(
        array $seed,
        object $import,
        int $age,
        string $createdAt,
        string $arrivalAt,
    ): array {
        return [
            'import' => $import,
            'hospital' => $seed['hospital'],
            'state' => $seed['state'],
            'dispatchArea' => $seed['dispatchArea'],
            'createdAt' => new \DateTimeImmutable($createdAt),
            'arrivalAt' => new \DateTimeImmutable($arrivalAt),
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'age' => $age,
            'requiresResus' => false,
            'requiresCathlab' => true,
            'isCPR' => false,
            'isVentilated' => true,
            'isWorkAccident' => false,
            'isWithPhysician' => true,
            'isShock' => false,
            'isPregnant' => false,
            'occasion' => null,
            'infection' => null,
            'indicationNormalized' => $seed['indicationNormalized'],
        ];
    }
}
