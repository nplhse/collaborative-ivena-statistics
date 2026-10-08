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
use App\Statistics\ClosureAnalytics\Application\ClosureEventAssignmentPopulation;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeStratum;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureEventAllocationQuery;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ClosureEventAllocationQueryTest extends KernelTestCase
{
    use Factories;

    public function testHalfOpenBoundsPopulationsAndDistinctMembers(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'closure-event-alloc']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Event Alloc Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $otherHospital = HospitalFactory::createOne(['name' => 'Other Event Alloc Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $speciality = SpecialityFactory::createOne(['name' => 'Event Alloc Speciality']);
        $otherSpeciality = SpecialityFactory::createOne(['name' => 'Other Event Alloc Speciality']);
        $closedDepartment = DepartmentFactory::createOne(['name' => 'Closed Event Department']);
        $openDepartment = DepartmentFactory::createOne(['name' => 'Open Event Department']);
        AssignmentFactory::createOne(['name' => 'Event Alloc Assign']);
        IndicationRawFactory::createOne(['name' => 'Event Alloc Raw', 'code' => 811]);
        IndicationNormalizedFactory::createOne(['name' => 'Event Alloc Indication']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $otherImport = ImportFactory::createOne(['hospital' => $otherHospital, 'createdBy' => $user]);
        $connection = self::getContainer()->get(Connection::class);
        $eventId = (int) $connection->fetchOne(
            <<<'SQL'
INSERT INTO closure_event (hospital_id, event_type, grouping_key, grouping_rule, starts_at, ends_at)
VALUES (:hospital, 'single', :grouping_key, 'test', '2026-05-01 10:00:00', '2026-05-01 12:00:00')
RETURNING id
SQL,
            [
                'hospital' => $hospital->getId(),
                'grouping_key' => 'event-alloc-'.$hospital->getId(),
            ],
        );
        foreach ([1, 2] as $member) {
            $connection->executeStatement(
                <<<'SQL'
INSERT INTO closure_analysis_interval (
    event_id, hospital_id, speciality_id, department_id, starts_at, ends_at, reason, facility_kind, fingerprint
) VALUES (
    :event, :hospital, :speciality, :department, '2026-05-01 10:00:00', '2026-05-01 12:00:00', 'no_bed_capacity', 'clinic', :fingerprint
)
SQL,
                [
                    'event' => $eventId,
                    'hospital' => $hospital->getId(),
                    'speciality' => $speciality->getId(),
                    'department' => $closedDepartment->getId(),
                    'fingerprint' => 'event-alloc-'.$eventId.'-'.$member,
                ],
            );
        }

        $berlin = new \DateTimeZone('Europe/Berlin');
        $defaults = [
            'state' => $state,
            'dispatchArea' => $dispatch,
            'urgency' => AllocationUrgency::EMERGENCY,
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 11:30:00', $berlin),
            'departmentWasClosed' => false,
        ];
        $insideClosed = AllocationFactory::createOne([
            ...$defaults,
            'import' => $import,
            'hospital' => $hospital,
            'speciality' => $speciality,
            'department' => $closedDepartment,
            'createdAt' => new \DateTimeImmutable('2026-05-01 10:30:00', $berlin),
            'departmentWasClosed' => true,
            'indicationNormalized' => IndicationNormalizedFactory::random(),
        ]);
        $insideOpen = AllocationFactory::createOne([
            ...$defaults,
            'import' => $import,
            'hospital' => $hospital,
            'speciality' => $speciality,
            'department' => $openDepartment,
            'createdAt' => new \DateTimeImmutable('2026-05-01 12:30:00', $berlin),
        ]);
        AllocationFactory::createOne([
            ...$defaults,
            'import' => $import,
            'hospital' => $hospital,
            'speciality' => $speciality,
            'department' => $openDepartment,
            'createdAt' => new \DateTimeImmutable('2026-05-01 11:30:00', $berlin),
        ]);
        AllocationFactory::createOne([
            ...$defaults,
            'import' => $import,
            'hospital' => $hospital,
            'speciality' => $speciality,
            'department' => $closedDepartment,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2026-05-01 11:45:00', $berlin),
        ]);
        AllocationFactory::createOne([
            ...$defaults,
            'import' => $import,
            'hospital' => $hospital,
            'speciality' => $speciality,
            'department' => $closedDepartment,
            'createdAt' => new \DateTimeImmutable('2026-05-01 12:00:00', $berlin),
        ]);
        AllocationFactory::createOne([
            ...$defaults,
            'import' => $otherImport,
            'hospital' => $otherHospital,
            'speciality' => $speciality,
            'department' => $closedDepartment,
            'createdAt' => new \DateTimeImmutable('2026-05-01 11:00:00', $berlin),
        ]);

        $query = self::getContainer()->get(ClosureEventAllocationQuery::class);
        $start = new \DateTimeImmutable('2026-05-01 10:00:00', $berlin);
        $end = new \DateTimeImmutable('2026-05-01 12:00:00', $berlin);
        $emergencies = $query->emergencies($eventId, (int) $hospital->getId());

        self::assertSame(2, $emergencies['total']);
        self::assertCount(2, $emergencies['rows']);
        self::assertSame('Closed Event Department', $emergencies['rows'][0]->departmentName);
        self::assertTrue($emergencies['rows'][0]->closedAtAssignmentTime);
        self::assertSame(AllocationUrgency::INPATIENT, $emergencies['rows'][1]->urgency);

        $specialityCounts = $query->phaseCounts(
            $eventId,
            (int) $hospital->getId(),
            $start,
            $end,
            ClosureEventAssignmentPopulation::Speciality,
            ClosureVolumeStratum::All,
        );
        self::assertSame(0, $specialityCounts['before']);
        self::assertSame(3, $specialityCounts['during']);
        self::assertSame(2, $specialityCounts['after']);
        $specialityRows = $query->assignments(
            $eventId,
            (int) $hospital->getId(),
            $start,
            $end,
            ClosureEventAssignmentPopulation::Speciality,
            ClosureVolumeStratum::All,
            0,
            10,
        );
        self::assertCount(3, $specialityRows);
        $specialityDepartments = array_map(static fn ($row): string => $row->departmentName, $specialityRows);
        self::assertContains('Open Event Department', $specialityDepartments);
        self::assertContains('Closed Event Department', $specialityDepartments);

        $closedRows = $query->assignments(
            $eventId,
            (int) $hospital->getId(),
            $start,
            $end,
            ClosureEventAssignmentPopulation::Closed,
            ClosureVolumeStratum::All,
            0,
            10,
        );
        self::assertCount(2, $closedRows);
        foreach ($closedRows as $row) {
            self::assertSame('Closed Event Department', $row->departmentName);
        }
        self::assertTrue($closedRows[0]->departmentWasClosed);
        self::assertSame($insideClosed->getPublicIdString(), $closedRows[0]->publicId);
        self::assertNotContains(
            $insideOpen->getPublicIdString(),
            array_map(static fn ($row): string => $row->publicId, $closedRows),
        );
    }

    public function testPaginationReturnsOnlyTheRequestedPage(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'closure-event-page']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Event Page Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $speciality = SpecialityFactory::createOne(['name' => 'Event Page Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Event Page Department']);
        AssignmentFactory::createOne(['name' => 'Event Page Assign']);
        IndicationRawFactory::createOne(['name' => 'Event Page Raw', 'code' => 812]);
        IndicationNormalizedFactory::createOne(['name' => 'Event Page Indication']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $connection = self::getContainer()->get(Connection::class);
        $eventId = (int) $connection->fetchOne(
            <<<'SQL'
INSERT INTO closure_event (hospital_id, event_type, grouping_key, grouping_rule, starts_at, ends_at)
VALUES (:hospital, 'single', :grouping_key, 'test', '2026-05-01 10:00:00', '2026-05-01 12:00:00')
RETURNING id
SQL,
            [
                'hospital' => $hospital->getId(),
                'grouping_key' => 'event-page-'.$hospital->getId(),
            ],
        );
        $connection->executeStatement(
            <<<'SQL'
INSERT INTO closure_analysis_interval (
    event_id, hospital_id, speciality_id, department_id, starts_at, ends_at, reason, facility_kind, fingerprint
) VALUES (
    :event, :hospital, :speciality, :department, '2026-05-01 10:00:00', '2026-05-01 12:00:00', 'no_bed_capacity', 'clinic', :fingerprint
)
SQL,
            [
                'event' => $eventId,
                'hospital' => $hospital->getId(),
                'speciality' => $speciality->getId(),
                'department' => $department->getId(),
                'fingerprint' => 'event-page-'.$eventId,
            ],
        );
        for ($minute = 0; $minute < 11; ++$minute) {
            AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatch,
                'speciality' => $speciality,
                'department' => $department,
                'urgency' => AllocationUrgency::EMERGENCY,
                'arrivalAt' => new \DateTimeImmutable('2026-05-01 11:30:00'),
                'createdAt' => new \DateTimeImmutable(sprintf('2026-05-01 10:%02d:00', $minute)),
            ]);
        }

        $query = self::getContainer()->get(ClosureEventAllocationQuery::class);
        $berlin = new \DateTimeZone('Europe/Berlin');
        $rows = $query->assignments(
            $eventId,
            (int) $hospital->getId(),
            new \DateTimeImmutable('2026-05-01 10:00:00', $berlin),
            new \DateTimeImmutable('2026-05-01 12:00:00', $berlin),
            ClosureEventAssignmentPopulation::Closed,
            ClosureVolumeStratum::All,
            0,
            ClosureEventAllocationQuery::PAGE_SIZE,
        );
        $next = $query->assignments(
            $eventId,
            (int) $hospital->getId(),
            new \DateTimeImmutable('2026-05-01 10:00:00', $berlin),
            new \DateTimeImmutable('2026-05-01 12:00:00', $berlin),
            ClosureEventAssignmentPopulation::Closed,
            ClosureVolumeStratum::All,
            ClosureEventAllocationQuery::PAGE_SIZE,
            ClosureEventAllocationQuery::PAGE_SIZE,
        );

        self::assertCount(ClosureEventAllocationQuery::PAGE_SIZE, $rows);
        self::assertCount(1, $next);
    }
}
