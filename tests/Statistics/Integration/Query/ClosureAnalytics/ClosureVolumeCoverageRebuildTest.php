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
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use App\Tests\Statistics\Support\RebuildsClosureAnalysis;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ClosureVolumeCoverageRebuildTest extends KernelTestCase
{
    use Factories;
    use RebuildsClosureAnalysis;

    #[Test]
    public function legacyCoverageTreatsDaysWithoutAllocationsAsMissing(): void
    {
        $connection = $this->build(false);

        self::assertNull($this->observedInClosure($connection));
    }

    #[Test]
    public function derivedCoverageTreatsCoveredDaysWithoutAllocationsAsZero(): void
    {
        $connection = $this->build(true);

        self::assertSame('0.0000', $this->observedInClosure($connection));
    }

    private function build(bool $derived): Connection
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $seed = $this->seedClosureGraph($connection);
        $connection->executeStatement(
            "INSERT INTO closure_interval (hospital_id, import_id, speciality_id, department_id, starts_at, ends_at, care_level, reason, facility_kind, closure_unit, source_group_id, source_recorded_at, source_changed_at)
             SELECT hospital_id, import_id, speciality_id, department_id, '2026-06-09 10:00:00', '2026-06-09 11:00:00', care_level, reason, facility_kind, closure_unit, 'quiet-day-group', source_recorded_at, source_changed_at
             FROM closure_interval WHERE import_id = :import",
            ['import' => $seed['importId']],
        );
        $connection->executeStatement(
            "UPDATE import SET type = 'Allocation', status = 'Completed', export_starts_at = :starts, export_ends_at = :ends, export_period_source = :source WHERE id = :import",
            [
                'import' => $seed['importId'],
                'starts' => $derived ? '2026-05-01 00:00:00' : null,
                'ends' => $derived ? '2026-07-01 00:00:00' : null,
                'source' => $derived ? 'derived' : null,
            ],
        );
        $this->rebuildClosureAnalysis();
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($seed['importId']);
        self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->rebuild();

        return $connection;
    }

    private function observedInClosure(Connection $connection): ?string
    {
        $value = $connection->fetchOne(
            "SELECT h.observed_area
             FROM closure_volume_hour h
             INNER JOIN closure_event e ON e.id = h.event_id
             WHERE e.source_group_id = 'quiet-day-group' AND h.scope = 'department' AND h.metric_kind IN ('emergency', 'inpatient', 'outpatient') AND h.closure_relevance <> 'none' AND h.in_closure
             ORDER BY h.bucket_start
             LIMIT 1",
        );
        self::assertNotFalse($value, 'The quiet-day closure has an in-closure row.');

        return null === $value ? null : (string) $value;
    }

    /**
     * @return array{importId: int}
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

        return ['importId' => $importId];
    }
}
