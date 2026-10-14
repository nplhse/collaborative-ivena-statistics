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
use App\Statistics\Application\Contract\ClosureVolumeDiagnosticsInterface;
use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use App\Statistics\Application\Contract\ClosureVolumeSnapshotInterface;
use App\Tests\Statistics\Support\RebuildsClosureAnalysis;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ClosureVolumeDiagnosticsTest extends KernelTestCase
{
    use Factories;
    use RebuildsClosureAnalysis;

    #[Test]
    public function snapshotDetectsIdenticalAndChangedProjection(): void
    {
        $connection = $this->buildProjection();
        $snapshots = self::getContainer()->get(ClosureVolumeSnapshotInterface::class);

        $rows = $snapshots->save('baseline');
        self::assertGreaterThan(0, $rows);
        self::assertSame([['name' => 'baseline', 'rows' => $rows]], $snapshots->list());

        $identical = $snapshots->compare('baseline');
        self::assertSame(0, $identical['changed_rows'] + $identical['only_in_snapshot'] + $identical['only_in_current']);

        $connection->executeStatement(
            "UPDATE closure_volume_hour SET expected_area = coalesce(expected_area, 0) + 1 WHERE id = (SELECT MIN(id) FROM closure_volume_hour WHERE stratum = 'base')",
        );
        $changed = $snapshots->compare('baseline');
        self::assertSame(1, $changed['changed_rows']);
        self::assertSame(1, $changed['changed_columns']['expected_area']);
        self::assertCount(1, $changed['largest_differences']);

        $snapshots->drop('baseline');
        self::assertSame([], $snapshots->list());
    }

    #[Test]
    public function diagnosticsReportEveryHospitalWithClosures(): void
    {
        $this->buildProjection();
        $diagnostics = self::getContainer()->get(ClosureVolumeDiagnosticsInterface::class);

        self::assertNotSame([], $diagnostics->referenceQuality(null));
        $coverage = $diagnostics->coverage(null);
        self::assertCount(2, $coverage);
        self::assertFalse($coverage[0]['derived']);
        self::assertCount(1, $diagnostics->exportBoundaries(null));
        $provisional = $diagnostics->provisional(null, 4);
        self::assertCount(1, $provisional);
        self::assertTrue($provisional[0]['not_derived']);
    }

    #[Test]
    public function snapshotNamesAreValidated(): void
    {
        self::bootKernel();
        $this->expectException(\InvalidArgumentException::class);

        self::getContainer()->get(ClosureVolumeSnapshotInterface::class)->save('Bad-Name');
    }

    private function buildProjection(): Connection
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $seed = $this->seedClosureGraph($connection);
        $this->rebuildClosureAnalysis();
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($seed['importId']);
        self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->rebuild();

        return $connection;
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
