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
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Contract\ClosureRebuildRequestInterface;
use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * ADR 015 phase 4: after every change, the incremental rebuild must leave exactly the rows a full
 * rebuild of the hospital produces, while rebuilding only the events the change can reach.
 */
#[ResetDatabase]
final class ClosureVolumeIncrementalRebuildTest extends KernelTestCase
{
    use Factories;

    /** @var array<string, mixed> */
    private array $seed = [];

    #[Test]
    public function allocationsBeforeEveryReferenceRebuildOnlyTheProvisionalEvents(): void
    {
        $this->build();
        $this->allocationImport('2026-02-02', '2026-02-09', [['2026-02-03 10:10:00', 'd1']]);

        $events = $this->assertIncrementalEqualsFull();

        self::assertSame(0, $events, 'No event can see February and none is provisional.');
    }

    #[Test]
    public function allocationsInsideTheReferenceRebuildTheEventsThatSeeThem(): void
    {
        $this->build();
        // All scenario closures fall on Tuesdays; the reference compares the same weekday.
        $this->allocationImport('2026-04-28', '2026-04-29', [['2026-04-28 10:20:00', 'd1'], ['2026-04-28 11:40:00', 'd2']]);

        $events = $this->assertIncrementalEqualsFull();

        self::assertSame(3, $events, 'The events of 7 April, 21 April and 19 May reach 28 April; 2 June does not.');
    }

    #[Test]
    public function correctedAndDeletedAllocationsAreRebuilt(): void
    {
        $this->build();
        $import = $this->allocationImport('2026-04-14', '2026-04-15', [['2026-04-14 10:20:00', 'd1'], ['2026-04-14 11:40:00', 'd2']]);
        $this->assertIncrementalEqualsFull();

        $this->connection()->executeStatement('UPDATE allocation SET requires_resus = TRUE WHERE import_id = ?', [$import]);
        $this->rebuildProjection($import);
        $this->requests()->requestVolume($this->hospitalId(), new \DateTimeImmutable('2026-04-14'), new \DateTimeImmutable('2026-04-15'));
        $this->assertIncrementalEqualsFull();

        $this->connection()->executeStatement('DELETE FROM allocation_stats_projection WHERE import_id = ?', [$import]);
        $this->connection()->executeStatement('DELETE FROM allocation WHERE import_id = ?', [$import]);
        $this->requests()->requestVolume($this->hospitalId(), new \DateTimeImmutable('2026-04-14'), new \DateTimeImmutable('2026-04-15'));
        $this->assertIncrementalEqualsFull();
    }

    #[Test]
    public function changedAndRemovedClosuresRebuildTheirNeighbours(): void
    {
        $this->build();
        $this->connection()->executeStatement(
            "UPDATE closure_interval SET care_level = 'other' WHERE starts_at = '2026-04-07 10:00:00'",
        );
        $this->requests()->requestAnalysis($this->hospitalId(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-05-01'));
        $this->assertIncrementalEqualsFull();

        $this->connection()->executeStatement("DELETE FROM closure_interval WHERE starts_at = '2026-05-19 08:00:00'");
        $this->requests()->requestAnalysis($this->hospitalId(), new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01'));
        $this->assertIncrementalEqualsFull();
        self::assertSame(3, (int) $this->connection()->fetchOne('SELECT COUNT(DISTINCT event_id) FROM closure_volume_hour'));
    }

    #[Test]
    public function changedGroupingReplacesTheEvent(): void
    {
        $this->build();
        $before = $this->connection()->fetchFirstColumn('SELECT DISTINCT event_id FROM closure_volume_hour ORDER BY 1');
        // The two simultaneous closures of 21 April form a cluster; a shared IVENA group id turns them into a group event.
        $this->connection()->executeStatement(
            "UPDATE closure_interval SET source_group_id = '4711' WHERE starts_at = '2026-04-21 08:00:00'",
        );
        $this->requests()->requestAnalysis($this->hospitalId(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-05-01'));

        $this->assertIncrementalEqualsFull();

        $after = $this->connection()->fetchFirstColumn('SELECT DISTINCT event_id FROM closure_volume_hour ORDER BY 1');
        self::assertNotSame($before, $after, 'The cluster event is gone and the group event has rows.');
        self::assertCount(4, $after);
    }

    #[Test]
    public function aNoOpImportRebuildsWithoutChangingAnything(): void
    {
        $this->build();
        $before = $this->rows();
        $this->requests()->requestVolume($this->hospitalId(), new \DateTimeImmutable('2026-06-15'), new \DateTimeImmutable('2026-06-16'));

        $this->assertIncrementalEqualsFull();

        self::assertSame($before, $this->rows());
    }

    #[Test]
    public function provisionalEventsBecomeFinalWithTheMissingImports(): void
    {
        $this->build(allocationsUntil: '2026-06-01', closuresUntil: '2026-06-01');
        self::assertSame(
            ['awaiting_allocations'],
            $this->connection()->fetchFirstColumn('SELECT DISTINCT provisional_reason FROM closure_volume_hour WHERE provisional'),
        );

        $this->allocationImport('2026-06-01', '2026-07-01', $this->dailyAllocations('2026-06-01', '2026-07-01'));
        $this->assertIncrementalEqualsFull();
        self::assertSame(
            ['awaiting_closures'],
            $this->connection()->fetchFirstColumn('SELECT DISTINCT provisional_reason FROM closure_volume_hour WHERE provisional'),
        );

        $this->closureImport('2026-06-01', '2026-07-01', []);
        $this->assertIncrementalEqualsFull();
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM closure_volume_hour WHERE provisional'));
    }

    #[Test]
    public function chronologicalMonthlyImportsMatchAFullRebuildAfterEveryMonth(): void
    {
        $this->seedHospital();
        $this->allocationImport('2026-03-01', '2026-04-01', $this->dailyAllocations('2026-03-01', '2026-04-01'));
        $this->closureImport('2026-03-01', '2026-04-01', []);
        $this->rebuildAll();
        foreach ([['2026-04-01', '2026-05-01'], ['2026-05-01', '2026-06-01'], ['2026-06-01', '2026-07-01']] as [$from, $to]) {
            $this->allocationImport($from, $to, $this->dailyAllocations($from, $to));
            $this->closureImport($from, $to, $this->closuresIn($from, $to));
            $this->assertIncrementalEqualsFull();
        }
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM closure_volume_hour WHERE provisional AND bucket_start < ?', ['2026-06-01']));
    }

    /**
     * Runs the queued requests incrementally, then rebuilds the hospital in full and compares.
     *
     * @return int events the incremental run rebuilt
     */
    private function assertIncrementalEqualsFull(): int
    {
        $rebuild = self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class);
        $rebuild->consume();
        $events = (int) $this->connection()->fetchOne("SELECT metrics->>'events' FROM job_run WHERE job = 'closure_rebuild' ORDER BY started_at DESC, id DESC LIMIT 1");
        $incremental = $this->rows();
        self::assertNotEmpty($incremental);

        $rebuild->rebuild(null, [$this->hospitalId()]);

        self::assertSame($this->rows(), $incremental, 'The incremental rebuild must match a full rebuild.');

        return $events;
    }

    private function build(string $allocationsUntil = '2026-07-01', string $closuresUntil = '2026-07-01'): void
    {
        $this->seedHospital();
        $this->allocationImport('2026-03-01', $allocationsUntil, $this->dailyAllocations('2026-03-01', $allocationsUntil));
        foreach ([['2026-04-01', '2026-05-01'], ['2026-05-01', '2026-06-01'], ['2026-06-01', '2026-07-01']] as [$from, $to]) {
            if ($from < $closuresUntil) {
                $this->closureImport($from, $to, $this->closuresIn($from, $to));
            }
        }
        $this->rebuildAll();
    }

    private function rebuildAll(): void
    {
        $this->connection()->executeStatement('DELETE FROM closure_rebuild_request');
        self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->rebuild(null, [$this->hospitalId()]);
    }

    private function seedHospital(): void
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatch, 'owner' => $user, 'createdBy' => $user]);
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne(['code' => random_int(700, 799)]);
        IndicationNormalizedFactory::createOne();
        $this->seed = [
            'user' => $user,
            'state' => $state,
            'dispatch' => $dispatch,
            'hospital' => $hospital,
            'speciality' => SpecialityFactory::createOne(['name' => 'Incremental Speciality']),
            'd1' => DepartmentFactory::createOne(['name' => 'Incremental D1']),
            'd2' => DepartmentFactory::createOne(['name' => 'Incremental D2']),
        ];
    }

    /**
     * Two allocations a day in D1 and one in D2; every fifth needs the shock room.
     *
     * @return list<array{0: string, 1: string, 2?: bool}>
     */
    private function dailyAllocations(string $from, string $to): array
    {
        $allocations = [];
        $index = 0;
        for ($day = new \DateTimeImmutable($from); $day < new \DateTimeImmutable($to); $day = $day->modify('+1 day')) {
            foreach ([['10:10:00', 'd1'], ['14:40:00', 'd1'], ['11:30:00', 'd2']] as [$time, $department]) {
                $allocations[] = [$day->format('Y-m-d').' '.$time, $department, 0 === ++$index % 5];
            }
        }

        return $allocations;
    }

    /**
     * Closures of the test scenario that start inside [from, to).
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function closuresIn(string $from, string $to): array
    {
        $all = [
            ['2026-04-07 10:00:00', '2026-04-07 14:00:00', 'd1', 'emergency'],
            ['2026-04-21 08:00:00', '2026-04-21 20:00:00', 'd1', 'other'],
            ['2026-04-21 08:00:00', '2026-04-21 20:00:00', 'd2', 'inpatient'],
            ['2026-05-19 08:00:00', '2026-05-19 11:00:00', 'd2', 'emergency'],
            ['2026-06-02 06:00:00', '2026-06-02 09:00:00', 'd1', 'outpatient'],
        ];

        return array_values(array_filter($all, static fn (array $closure): bool => $closure[0] >= $from && $closure[0] < $to));
    }

    /**
     * Adds an allocation import with a derived export period and queues its volume request, like the import pipeline.
     *
     * @param list<array{0: string, 1: string, 2?: bool}> $allocations created_at, department key, requires resus
     */
    private function allocationImport(string $from, string $to, array $allocations): int
    {
        $import = ImportFactory::createOne([
            'hospital' => $this->seed['hospital'],
            'createdBy' => $this->seed['user'],
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::COMPLETED,
        ]);
        foreach ($allocations as $allocation) {
            AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $this->seed['hospital'],
                'state' => $this->seed['state'],
                'dispatchArea' => $this->seed['dispatch'],
                'speciality' => $this->seed['speciality'],
                'department' => $this->seed[$allocation[1]],
                'createdAt' => new \DateTimeImmutable($allocation[0]),
                'arrivalAt' => new \DateTimeImmutable($allocation[0])->modify('+20 minutes'),
                'urgency' => AllocationUrgency::EMERGENCY,
                'requiresResus' => $allocation[2] ?? false,
                'requiresCathlab' => false,
            ]);
        }
        $importId = (int) $import->getId();
        $this->derivePeriod($importId, $from, $to);
        $this->rebuildProjection($importId);
        $this->requests()->requestVolume($this->hospitalId(), new \DateTimeImmutable($from), new \DateTimeImmutable($to));

        return $importId;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $closures start, end, department key, care level
     */
    private function closureImport(string $from, string $to, array $closures): void
    {
        $import = ImportFactory::createOne([
            'hospital' => $this->seed['hospital'],
            'createdBy' => $this->seed['user'],
            'type' => ImportType::CLOSURE,
            'status' => ImportStatus::COMPLETED,
        ]);
        $importId = (int) $import->getId();
        foreach ($closures as [$start, $end, $department, $careLevel]) {
            $this->connection()->insert('closure_interval', [
                'hospital_id' => $this->hospitalId(),
                'import_id' => $importId,
                'speciality_id' => (int) $this->seed['speciality']->getId(),
                'department_id' => (int) $this->seed[$department]->getId(),
                'starts_at' => $start,
                'ends_at' => $end,
                'care_level' => $careLevel,
                'reason' => 'no_bed_capacity',
                'facility_kind' => 'clinic',
                'closure_unit' => 'unit '.$department,
                'source_group_id' => null,
                'source_recorded_at' => $start,
                'source_changed_at' => $start,
            ]);
        }
        $this->derivePeriod($importId, $from, $to);
        $this->requests()->requestAnalysis($this->hospitalId(), new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    private function derivePeriod(int $importId, string $from, string $to): void
    {
        $this->connection()->executeStatement(
            "UPDATE import SET export_starts_at = ?, export_ends_at = ?, export_period_source = 'derived' WHERE id = ?",
            [$from.' 00:00:00', $to.' 00:00:00', $importId],
        );
    }

    private function rebuildProjection(int $importId): void
    {
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($importId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->connection()->fetchAllAssociative(
            <<<'SQL'
SELECT event_id, scope, speciality_id, department_id, metric_kind, bucket_start, bucket_end, in_closure,
       observed_area, expected_area, observed_hospital, expected_hospital, evaluable_seconds, bucket_seconds,
       reference_slot_count, blocked_slot_count, reference_assignment_count, reference_mode, influenced, quality,
       closure_relevance, closed_department_count, provisional, provisional_reason, reference_cutoff
FROM closure_volume_hour
ORDER BY event_id, scope, speciality_id, department_id, metric_kind, bucket_start
SQL,
        );
    }

    private function requests(): ClosureRebuildRequestInterface
    {
        return self::getContainer()->get(ClosureRebuildRequestInterface::class);
    }

    private function hospitalId(): int
    {
        return (int) $this->seed['hospital']->getId();
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
