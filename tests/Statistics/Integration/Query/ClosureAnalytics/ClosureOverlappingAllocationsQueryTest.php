<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\ClosureAnalytics;

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
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureOverlapWindow;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureOverlappingAllocationsQuery;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ClosureOverlappingAllocationsQueryTest extends KernelTestCase
{
    use Factories;

    public function testMatchesHalfOpenBoundsDepartmentAndHospital(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'closure-overlap-alloc']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Overlap Alloc Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $otherHospital = HospitalFactory::createOne(['name' => 'Other Alloc Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $speciality = SpecialityFactory::createOne(['name' => 'Overlap Alloc Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Overlap Alloc Department']);
        $otherDepartment = DepartmentFactory::createOne(['name' => 'Other Alloc Department']);
        AssignmentFactory::createOne(['name' => 'Overlap Assign']);
        IndicationRawFactory::createOne(['name' => 'Overlap Raw', 'code' => 701]);
        IndicationNormalizedFactory::createOne(['name' => 'Overlap Indication']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $otherImport = ImportFactory::createOne(['hospital' => $otherHospital, 'createdBy' => $user]);

        $defaults = [
            'state' => $state,
            'dispatchArea' => $dispatch,
            'speciality' => $speciality,
            'urgency' => AllocationUrgency::EMERGENCY,
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 11:30:00'),
        ];
        $berlin = new \DateTimeZone('Europe/Berlin');
        $insideStart = AllocationFactory::createOne([
            ...$defaults,
            'import' => $import,
            'hospital' => $hospital,
            'department' => $department,
            'createdAt' => new \DateTimeImmutable('2026-05-01 10:00:00'),
            'indicationNormalized' => IndicationNormalizedFactory::random(),
        ]);
        AllocationFactory::createOne([
            ...$defaults,
            'import' => $import,
            'hospital' => $hospital,
            'department' => $department,
            'createdAt' => new \DateTimeImmutable('2026-05-01 12:00:00'),
        ]);
        AllocationFactory::createOne([
            ...$defaults,
            'import' => $import,
            'hospital' => $hospital,
            'department' => $department,
            'createdAt' => new \DateTimeImmutable('2026-05-01 09:59:00'),
        ]);
        AllocationFactory::createOne([
            ...$defaults,
            'import' => $import,
            'hospital' => $hospital,
            'department' => $department,
            'createdAt' => new \DateTimeImmutable('2026-05-01 12:01:00'),
        ]);
        AllocationFactory::createOne([
            ...$defaults,
            'import' => $import,
            'hospital' => $hospital,
            'department' => $otherDepartment,
            'createdAt' => new \DateTimeImmutable('2026-05-01 11:00:00'),
        ]);
        AllocationFactory::createOne([
            ...$defaults,
            'import' => $otherImport,
            'hospital' => $otherHospital,
            'department' => $department,
            'createdAt' => new \DateTimeImmutable('2026-05-01 11:00:00'),
        ]);

        $connection = self::getContainer()->get(Connection::class);
        $intervalId = $this->insertAnalysisInterval(
            $connection,
            (int) $hospital->getId(),
            (int) $speciality->getId(),
            (int) $department->getId(),
            'emergency',
        );

        $query = self::getContainer()->get(ClosureOverlappingAllocationsQuery::class);
        $rows = $query->fetch([
            new ClosureOverlapWindow(
                (int) $hospital->getId(),
                (int) $department->getId(),
                new \DateTimeImmutable('2026-05-01 10:00:00', $berlin),
                new \DateTimeImmutable('2026-05-01 12:00:00', $berlin),
                $intervalId,
            ),
        ]);

        self::assertCount(1, $rows);
        self::assertSame($insideStart->getPublicIdString(), $rows[0]->publicId);
        self::assertSame('Overlap Alloc Department', $rows[0]->departmentName);
        self::assertSame(AllocationUrgency::EMERGENCY, $rows[0]->urgency);
    }

    public function testOverlappingChildWindowsDoNotDuplicateAllocations(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'closure-overlap-dup']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Dup Alloc Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $speciality = SpecialityFactory::createOne(['name' => 'Dup Alloc Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Dup Alloc Department']);
        AssignmentFactory::createOne(['name' => 'Dup Assign']);
        IndicationRawFactory::createOne(['name' => 'Dup Raw', 'code' => 702]);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatch,
            'speciality' => $speciality,
            'department' => $department,
            'createdAt' => new \DateTimeImmutable('2026-05-01 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 11:20:00'),
            'urgency' => AllocationUrgency::INPATIENT,
        ]);

        $connection = self::getContainer()->get(Connection::class);
        $hospitalId = (int) $hospital->getId();
        $departmentId = (int) $department->getId();
        $intervalId = $this->insertAnalysisInterval(
            $connection,
            $hospitalId,
            (int) $speciality->getId(),
            $departmentId,
            'inpatient',
        );

        $query = self::getContainer()->get(ClosureOverlappingAllocationsQuery::class);
        $berlin = new \DateTimeZone('Europe/Berlin');
        $rows = $query->fetch([
            new ClosureOverlapWindow(
                $hospitalId,
                $departmentId,
                new \DateTimeImmutable('2026-05-01 10:00:00', $berlin),
                new \DateTimeImmutable('2026-05-01 12:00:00', $berlin),
                $intervalId,
            ),
            new ClosureOverlapWindow(
                $hospitalId,
                $departmentId,
                new \DateTimeImmutable('2026-05-01 10:30:00', $berlin),
                new \DateTimeImmutable('2026-05-01 11:30:00', $berlin),
                $intervalId,
            ),
        ]);

        self::assertCount(1, $rows);
    }

    public function testEmptyWindowsYieldNoRows(): void
    {
        self::bootKernel();
        $query = self::getContainer()->get(ClosureOverlappingAllocationsQuery::class);

        self::assertSame([], $query->fetch([]));
    }

    private function insertAnalysisInterval(
        Connection $connection,
        int $hospitalId,
        int $specialityId,
        int $departmentId,
        string $careLevel,
    ): int {
        $eventId = (int) $connection->fetchOne(
            <<<'SQL'
INSERT INTO closure_event (hospital_id, event_type, grouping_key, grouping_rule, starts_at, ends_at)
VALUES (:hospital, 'single', :grouping_key, 'test', '2026-05-01 10:00:00', '2026-05-01 12:00:00')
RETURNING id
SQL,
            [
                'hospital' => $hospitalId,
                'grouping_key' => 'overlap-alloc-'.$hospitalId.'-'.bin2hex(random_bytes(4)),
            ],
        );
        $intervalId = (int) $connection->fetchOne(
            <<<'SQL'
INSERT INTO closure_analysis_interval (
    event_id, hospital_id, speciality_id, department_id, starts_at, ends_at, reason, facility_kind, fingerprint
) VALUES (
    :event, :hospital, :speciality, :department, '2026-05-01 10:00:00', '2026-05-01 12:00:00', 'no_bed_capacity', 'clinic', :fingerprint
) RETURNING id
SQL,
            [
                'event' => $eventId,
                'hospital' => $hospitalId,
                'speciality' => $specialityId,
                'department' => $departmentId,
                'fingerprint' => 'overlap-alloc-'.$eventId.'-'.$departmentId,
            ],
        );
        $connection->insert('closure_analysis_care_level', [
            'analysis_interval_id' => $intervalId,
            'care_level' => $careLevel,
        ]);

        return $intervalId;
    }
}
