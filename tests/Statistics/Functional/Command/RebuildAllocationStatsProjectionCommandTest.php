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
use App\Statistics\UI\Console\Command\RebuildAllocationStatsProjectionCommand;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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
        self::assertStringContainsString('Projection rebuild finished. Imports processed: 2', $display);
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
        self::assertStringContainsString('No allocations found. Projection table remains empty.', $tester->getDisplay());
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
