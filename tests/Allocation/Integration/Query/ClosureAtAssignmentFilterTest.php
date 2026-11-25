<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

use App\Allocation\Application\Allocations\ClosureAtAssignmentResolver;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\ClosureAtAssignment;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Query\ListAllocationsQuery;
use App\Allocation\UI\Http\DTO\AllocationQueryParametersDTO;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Hand-checkable fixture for "closed at assignment" (#571).
 *
 * Hospital A, closure import with export period [2026-03-01, 2026-04-01):
 * - D1, SK1 (emergency), 2026-03-10 08:00–12:00
 * - D1, other, 2026-03-20 10:00–11:00
 * - D2, SK1, 2026-03-15 00:00–2026-03-16 00:00, but from a failed import
 * Hospital B: no closure import.
 * Hospital C: closure import without export period, D1, SK1, 2026-03-10 08:00–12:00.
 *
 * Expected: closed a1, a4, a6, c1 (4); open a2, a3, a5, a7 (4); unknown a8, b1, c2 (3).
 */
#[ResetDatabase]
final class ClosureAtAssignmentFilterTest extends KernelTestCase
{
    use Factories;
    private ?User $administrator = null;

    /** @var array<string, int> */
    private array $ids = [];

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testListFilterSplitsAllocationsIntoThreeStates(): void
    {
        $this->seed();
        $query = self::getContainer()->get(ListAllocationsQuery::class);

        $names = function (ClosureAtAssignment $state) use ($query): array {
            $rows = $query->getPaginator(new AllocationQueryParametersDTO(closureAtAssignment: $state->value), $this->administrator())->getItems();
            $byId = array_flip($this->ids);
            $names = array_map(static fn (array $row): string => $byId[(int) $row['id']], $rows);
            sort($names);

            return $names;
        };

        self::assertSame(['a1', 'a4', 'a6', 'c1'], $names(ClosureAtAssignment::CLOSED));
        self::assertSame(['a2', 'a3', 'a5', 'a7'], $names(ClosureAtAssignment::OPEN));
        self::assertSame(['a8', 'b1', 'c2'], $names(ClosureAtAssignment::UNKNOWN));
    }

    public function testSingleAllocationUsesTheSameRule(): void
    {
        $this->seed();
        $query = self::getContainer()->get(ClosureAtAssignmentResolver::class);
        $repository = self::getContainer()->get('doctrine')->getRepository(Allocation::class);

        $state = fn (string $name): ClosureAtAssignment => $query->forAllocation($repository->find($this->ids[$name]));

        self::assertSame(ClosureAtAssignment::CLOSED, $state('a1'));
        self::assertSame(ClosureAtAssignment::OPEN, $state('a2'));
        self::assertSame(ClosureAtAssignment::UNKNOWN, $state('b1'));
        self::assertSame(ClosureAtAssignment::CLOSED, $state('c1'));
    }

    private function seed(): void
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $speciality = SpecialityFactory::createOne();
        $d1 = DepartmentFactory::createOne(['name' => 'D1']);
        $d2 = DepartmentFactory::createOne(['name' => 'D2']);
        IndicationRawFactory::createOne();
        AssignmentFactory::createOne();

        $hospitalA = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatchArea]);
        $hospitalB = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatchArea]);
        $hospitalC = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatchArea]);

        $closureA = $this->closureImport($hospitalA, $user, ImportStatus::COMPLETED, '2026-03-01', '2026-04-01');
        $failedA = $this->closureImport($hospitalA, $user, ImportStatus::FAILED, '2026-03-01', '2026-04-01');
        $closureC = $this->closureImport($hospitalC, $user, ImportStatus::COMPLETED, null, null);

        $this->interval($closureA, $hospitalA, $speciality, $d1, '2026-03-10 08:00', '2026-03-10 12:00', 'emergency');
        $this->interval($closureA, $hospitalA, $speciality, $d1, '2026-03-20 10:00', '2026-03-20 11:00', 'other');
        $this->interval($failedA, $hospitalA, $speciality, $d2, '2026-03-15 00:00', '2026-03-16 00:00', 'emergency');
        $this->interval($closureC, $hospitalC, $speciality, $d1, '2026-03-10 08:00', '2026-03-10 12:00', 'emergency');

        $allocationImport = ImportFactory::createOne([
            'hospital' => $hospitalA,
            'createdBy' => $user,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::COMPLETED,
        ]);
        $shared = [
            'import' => $allocationImport,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'speciality' => $speciality,
        ];

        $sk1 = AllocationUrgency::EMERGENCY;
        $this->allocation('a1', $shared, $hospitalA, $d1, $sk1, '2026-03-10 09:00');
        $this->allocation('a2', $shared, $hospitalA, $d1, AllocationUrgency::INPATIENT, '2026-03-10 09:00');
        $this->allocation('a3', $shared, $hospitalA, $d1, $sk1, '2026-03-10 12:00');
        $this->allocation('a4', $shared, $hospitalA, $d1, $sk1, '2026-03-10 08:00');
        $this->allocation('a5', $shared, $hospitalA, $d2, $sk1, '2026-03-10 09:00');
        $this->allocation('a6', $shared, $hospitalA, $d1, AllocationUrgency::OUTPATIENT, '2026-03-20 10:30');
        $this->allocation('a7', $shared, $hospitalA, $d2, $sk1, '2026-03-15 12:00');
        $this->allocation('a8', $shared, $hospitalA, $d1, $sk1, '2026-04-01 00:00');
        $this->allocation('b1', $shared, $hospitalB, $d1, $sk1, '2026-03-10 09:00');
        $this->allocation('c1', $shared, $hospitalC, $d1, $sk1, '2026-03-10 09:00');
        $this->allocation('c2', $shared, $hospitalC, $d1, $sk1, '2026-03-10 13:00');
    }

    private function closureImport(Hospital $hospital, object $user, ImportStatus $status, ?string $from, ?string $to): Import
    {
        return ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'type' => ImportType::CLOSURE,
            'status' => $status,
            'exportStartsAt' => null === $from ? null : new \DateTimeImmutable($from),
            'exportEndsAt' => null === $to ? null : new \DateTimeImmutable($to),
        ]);
    }

    private function interval(
        Import $import,
        Hospital $hospital,
        Speciality $speciality,
        Department $department,
        string $startsAt,
        string $endsAt,
        string $careLevel,
    ): void {
        self::getContainer()->get(Connection::class)->insert('closure_interval', [
            'hospital_id' => $hospital->getId(),
            'import_id' => $import->getId(),
            'speciality_id' => $speciality->getId(),
            'department_id' => $department->getId(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'care_level' => $careLevel,
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => null,
            'source_group_id' => null,
            'source_recorded_at' => $startsAt,
            'source_changed_at' => $startsAt,
        ]);
    }

    /**
     * @param array<string, object> $shared
     */
    private function allocation(
        string $name,
        array $shared,
        Hospital $hospital,
        Department $department,
        AllocationUrgency $urgency,
        string $createdAt,
    ): void {
        $created = new \DateTimeImmutable($createdAt);
        $allocation = AllocationFactory::createOne([
            'hospital' => $hospital,
            'department' => $department,
            'urgency' => $urgency,
            'createdAt' => $created,
            'arrivalAt' => $created->modify('+20 minutes'),
        ] + $shared);

        $this->ids[$name] = (int) $allocation->getId();
    }

    /**
     * Explore lists only the viewer's own hospitals (#1566); an administrator sees all of them.
     */
    private function administrator(): User
    {
        return $this->administrator ??= UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
    }
}
