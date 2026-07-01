<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Command;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssessmentFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\CaseId\CaseIdHasher;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\UI\Console\Command\DeduplicateProjectionCommand;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DeduplicateProjectionCommandTest extends KernelTestCase
{
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testDryRunReportsEnrDuplicateWithoutDeleting(): void
    {
        $this->seedEnrDuplicateFixture();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Dry run: no rows will be deleted.', $display);
        self::assertStringContainsString('Analyzing ENR duplicates', $display);
        self::assertStringContainsString('enr', $display);
        self::assertStringContainsString('Dry run finished. Re-run without --dry-run to apply changes.', $display);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection'));
    }

    public function testExecuteRemovesEnrDuplicateAndKeepsNewestImport(): void
    {
        $fixture = $this->seedEnrDuplicateFixture();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Deleting duplicates', $display);
        self::assertStringContainsString('Refreshing materialized views', $display);
        self::assertStringContainsString('Deduplication finished', $display);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection'));

        $keptId = (int) $connection->fetchOne('SELECT id FROM allocation');
        self::assertSame($fixture['newerAllocationId'], $keptId);
        self::assertSame(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation WHERE id = :id', ['id' => $fixture['olderAllocationId']]),
        );
    }

    public function testSameEnrDifferentEventIsNotTreatedAsDuplicate(): void
    {
        $this->seedEnrReuseAcrossYearsFixture();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation'));

        $display = $tester->getDisplay();
        self::assertStringContainsString('enr', $display);
        self::assertStringContainsString('spanning multiple years', $display);
        self::assertMatchesRegularExpression('/enr\s+\d+\s+0/', preg_replace('/\s+/', ' ', $display) ?? '');
    }

    public function testExecuteDeletesAssessmentLinkedToRemovedDuplicate(): void
    {
        $fixture = $this->seedEnrDuplicateFixture(withAssessmentOnOlderDuplicate: true);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM assessment WHERE id = :id',
            ['id' => $fixture['olderAssessmentId']],
        ));

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM assessment WHERE id = :id',
            ['id' => $fixture['olderAssessmentId']],
        ));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation'));
    }

    public function testExecuteRemovesFingerprintDuplicate(): void
    {
        $fixture = $this->seedFingerprintDuplicateFixture();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Analyzing fingerprint duplicates', $tester->getDisplay());

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation'));
        self::assertSame($fixture['newerAllocationId'], (int) $connection->fetchOne('SELECT id FROM allocation'));
    }

    public function testExecuteRemovesOrphanProjectionRow(): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(
            'INSERT INTO allocation_stats_projection (id, import_id, hospital_id, state_id, dispatch_area_id, speciality_id, department_id, occasion_id, assignment_id, infection_id, indication_normalized_id, created_at, arrival_at, created_year, created_quarter, created_month, created_week, created_day, created_weekday, created_hour, day_time_bucket_code, shift_bucket_code, transport_time_minutes, age, gender_code, urgency_code, transport_type_code, requires_resus, requires_cathlab, is_cpr, is_ventilated, is_work_accident, is_with_physician) VALUES (888888, 1, 1, 1, 1, 1, 1, NULL, 1, NULL, NULL, NOW(), NOW(), 2025, 1, 1, 1, 1, 1, 8, 2, 2, 10, 30, 1, 1, 1, false, false, false, false, false, false)',
        );

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection WHERE id = 888888'));

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Removing orphan projection rows', $tester->getDisplay());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection WHERE id = 888888'));
    }

    public function testNoDuplicatesSucceedsWithZeroRemovals(): void
    {
        $seed = $this->seedReferenceGraph();
        $import = ImportFactory::createOne([
            'name' => 'Unique Import',
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
        ]);
        $allocation = AllocationFactory::createOne($this->allocationDefaults(
            $seed,
            $import,
            25,
            '2025-05-01 08:00:00',
            '2025-05-01 08:30:00',
        ));
        $this->rebuildProjectionForImport((int) $import->getId());

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('Deduplication finished', $display);
        self::assertStringContainsString('Remaining allocations: 1', $display);
        self::assertSame((int) $allocation->getId(), (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM allocation'));
    }

    private function createCommandTester(): CommandTester
    {
        $command = self::getContainer()->get(DeduplicateProjectionCommand::class);

        return new CommandTester($command);
    }

    /**
     * @return array{olderAllocationId: int, newerAllocationId: int, olderAssessmentId?: int}
     */
    private function seedEnrDuplicateFixture(bool $withAssessmentOnOlderDuplicate = false): array
    {
        $seed = $this->seedReferenceGraph();
        /** @var CaseIdHasher $hasher */
        $hasher = self::getContainer()->get(CaseIdHasher::class);
        $caseIdHash = $hasher->hashFrom('123456789');

        $olderImport = ImportFactory::createOne([
            'name' => 'ENR Duplicate Import Old',
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'createdAt' => new \DateTimeImmutable('2024-01-01 10:00:00'),
        ]);
        $newerImport = ImportFactory::createOne([
            'name' => 'ENR Duplicate Import New',
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'createdAt' => new \DateTimeImmutable('2025-06-01 10:00:00'),
        ]);

        $defaults = $this->allocationDefaults($seed, $olderImport, 30, '2025-03-01 08:00:00', '2025-03-01 08:30:00');
        $defaults['caseIdHash'] = $caseIdHash;
        if ($withAssessmentOnOlderDuplicate) {
            $defaults['assessment'] = AssessmentFactory::createOne();
        }
        $olderAllocation = AllocationFactory::createOne($defaults);

        $newerDefaults = $this->allocationDefaults($seed, $newerImport, 30, '2025-03-01 08:00:00', '2025-03-01 08:30:00');
        $newerDefaults['caseIdHash'] = $caseIdHash;
        $newerAllocation = AllocationFactory::createOne($newerDefaults);

        $this->rebuildProjectionForImport((int) $olderImport->getId());
        $this->rebuildProjectionForImport((int) $newerImport->getId());

        $result = [
            'olderAllocationId' => (int) $olderAllocation->getId(),
            'newerAllocationId' => (int) $newerAllocation->getId(),
        ];

        if ($withAssessmentOnOlderDuplicate) {
            /** @var Connection $connection */
            $connection = self::getContainer()->get(Connection::class);
            $result['olderAssessmentId'] = (int) $connection->fetchOne(
                'SELECT assessment_id FROM allocation WHERE id = :id',
                ['id' => $result['olderAllocationId']],
            );
        }

        return $result;
    }

    private function seedEnrReuseAcrossYearsFixture(): void
    {
        $seed = $this->seedReferenceGraph();
        /** @var CaseIdHasher $hasher */
        $hasher = self::getContainer()->get(CaseIdHasher::class);
        $caseIdHash = $hasher->hashFrom('555001');

        $import2024 = ImportFactory::createOne([
            'name' => 'ENR Reuse Import 2024',
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'createdAt' => new \DateTimeImmutable('2024-06-01 10:00:00'),
        ]);
        $import2025 = ImportFactory::createOne([
            'name' => 'ENR Reuse Import 2025',
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'createdAt' => new \DateTimeImmutable('2025-06-01 10:00:00'),
        ]);

        $defaults2024 = $this->allocationDefaults($seed, $import2024, 45, '2024-03-15 08:00:00', '2024-03-15 08:40:00');
        $defaults2024['caseIdHash'] = $caseIdHash;
        AllocationFactory::createOne($defaults2024);

        $defaults2025 = $this->allocationDefaults($seed, $import2025, 52, '2025-03-15 08:00:00', '2025-03-15 08:40:00');
        $defaults2025['caseIdHash'] = $caseIdHash;
        AllocationFactory::createOne($defaults2025);

        $this->rebuildProjectionForImport((int) $import2024->getId());
        $this->rebuildProjectionForImport((int) $import2025->getId());
    }

    /**
     * @return array{newerAllocationId: int}
     */
    private function seedFingerprintDuplicateFixture(): array
    {
        $seed = $this->seedReferenceGraph();

        $olderImport = ImportFactory::createOne([
            'name' => 'Fingerprint Duplicate Import Old',
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'createdAt' => new \DateTimeImmutable('2024-02-01 10:00:00'),
        ]);
        $newerImport = ImportFactory::createOne([
            'name' => 'Fingerprint Duplicate Import New',
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'createdAt' => new \DateTimeImmutable('2025-07-01 10:00:00'),
        ]);

        $shared = $this->allocationDefaults($seed, $olderImport, 35, '2025-04-10 09:00:00', '2025-04-10 09:45:00');
        $shared['caseIdHash'] = null;
        AllocationFactory::createOne($shared);

        $newerDefaults = $this->allocationDefaults($seed, $newerImport, 35, '2025-04-10 09:00:00', '2025-04-10 09:45:00');
        $newerDefaults['caseIdHash'] = null;
        $newerAllocation = AllocationFactory::createOne($newerDefaults);

        $this->rebuildProjectionForImport((int) $olderImport->getId());
        $this->rebuildProjectionForImport((int) $newerImport->getId());

        return ['newerAllocationId' => (int) $newerAllocation->getId()];
    }

    private function rebuildProjectionForImport(int $importId): void
    {
        /** @var AllocationStatsProjectionRebuildInterface $rebuilder */
        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importId);
    }

    /**
     * @return array{
     *     user: object,
     *     state: object,
     *     dispatchArea: object,
     *     hospital: object,
     *     indicationNormalized: object,
     *     indicationRaw: object,
     *     speciality: object,
     *     department: object,
     *     assignment: object
     * }
     */
    private function seedReferenceGraph(): array
    {
        $user = UserFactory::createOne(['username' => 'dedup-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'DedupState-'.bin2hex(random_bytes(3))]);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'DedupDispatch-'.bin2hex(random_bytes(3)), 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'DedupHospital-'.bin2hex(random_bytes(3)),
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        $speciality = SpecialityFactory::createOne(['name' => 'DedupSpeciality-'.bin2hex(random_bytes(3))]);
        $department = DepartmentFactory::createOne(['name' => 'DedupDepartment-'.bin2hex(random_bytes(3))]);
        $assignment = AssignmentFactory::createOne(['name' => 'DedupAssignment-'.bin2hex(random_bytes(3))]);
        OccasionFactory::createOne(['name' => 'DedupOccasion-'.bin2hex(random_bytes(3))]);
        $indicationRaw = IndicationRawFactory::createOne(['name' => 'DedupRawIndication', 'code' => 700002]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'DedupNormalizedIndication']);

        return [
            'user' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'hospital' => $hospital,
            'speciality' => $speciality,
            'department' => $department,
            'assignment' => $assignment,
            'indicationNormalized' => $indicationNormalized,
            'indicationRaw' => $indicationRaw,
        ];
    }

    /**
     * @param array{
     *     user: object,
     *     state: object,
     *     dispatchArea: object,
     *     hospital: object,
     *     indicationNormalized: object,
     *     indicationRaw: object,
     *     speciality: object,
     *     department: object,
     *     assignment: object
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
            'speciality' => $seed['speciality'],
            'department' => $seed['department'],
            'assignment' => $seed['assignment'],
            'indicationRaw' => $seed['indicationRaw'],
            'indicationNormalized' => $seed['indicationNormalized'],
            'createdAt' => new \DateTimeImmutable($createdAt),
            'arrivalAt' => new \DateTimeImmutable($arrivalAt),
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'age' => $age,
            'requiresResus' => false,
            'requiresCathlab' => false,
            'isCPR' => false,
            'isVentilated' => false,
            'isWorkAccident' => false,
            'isWithPhysician' => false,
            'isShock' => false,
            'isPregnant' => false,
            'occasion' => null,
            'infection' => null,
            'departmentWasClosed' => false,
        ];
    }
}
