<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\ClosureAnalytics;

use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use App\Statistics\ClosureAnalytics\Infrastructure\Projection\ClosureAnalysisRebuilder;
use App\Statistics\ClosureAnalytics\Infrastructure\Projection\ClosureVolumeProjectionRebuilder;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ClosureAnalysisRebuilderTest extends KernelTestCase
{
    use Factories;

    public function testMergesCareLevelsKeepsDivergentRowsAndSurvivesVolumeRebuild(): void
    {
        self::bootKernel();
        $seed = $this->seed();
        $connection = self::getContainer()->get(Connection::class);
        $this->insert($connection, $seed, '2026-03-01 08:00:00', '2026-03-01 10:00:00', 'emergency', 'no_bed_capacity', 'grp', $seed['importId']);
        $this->insert($connection, $seed, '2026-03-01 08:00:00', '2026-03-01 10:00:00', 'inpatient', 'no_bed_capacity', 'grp', $seed['importId']);
        $this->insert($connection, $seed, '2026-03-01 08:00:00', '2026-03-01 10:00:00', 'other', 'no_bed_capacity', 'grp', $seed['secondImportId']);
        $this->insert($connection, $seed, '2026-03-01 08:00:00', '2026-03-01 10:00:00', 'emergency', 'technical_fault', 'grp', $seed['importId']);
        $openSibling = DepartmentFactory::createOne(['name' => 'Open sibling']);
        $this->insert($connection, $seed, '2026-03-02 08:00:00', '2026-03-02 09:00:00', 'emergency', 'no_bed_capacity', null, $seed['importId'], (int) $openSibling->getId());
        $this->insert($connection, $seed, '2026-03-02 08:00:00', '2026-03-02 09:00:00', 'emergency', 'no_bed_capacity', null, $seed['importId'], $seed['departmentId']);

        self::getContainer()->get(ClosureAnalysisRebuilder::class)->rebuild([$seed['hospitalId']]);

        $careLevels = $connection->fetchFirstColumn(
            <<<'SQL'
SELECT cl.care_level
FROM closure_analysis_interval ai
INNER JOIN closure_analysis_care_level cl ON cl.analysis_interval_id = ai.id
WHERE ai.hospital_id = :hospital AND ai.reason = 'no_bed_capacity' AND ai.source_group_id = 'grp'
ORDER BY cl.care_level
SQL,
            ['hospital' => $seed['hospitalId']],
        );
        self::assertSame(['emergency', 'inpatient', 'other'], $careLevels);
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM closure_analysis_interval WHERE hospital_id = :hospital AND source_group_id = 'grp' AND reason = 'no_bed_capacity'",
            ['hospital' => $seed['hospitalId']],
        ));
        self::assertSame(3, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM closure_analysis_source s INNER JOIN closure_analysis_interval ai ON ai.id = s.analysis_interval_id WHERE ai.reason = :reason AND ai.source_group_id = :group_id',
            ['reason' => 'no_bed_capacity', 'group_id' => 'grp'],
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM closure_event WHERE hospital_id = :hospital AND event_type = 'source_group' AND source_group_id = 'grp'",
            ['hospital' => $seed['hospitalId']],
        ));
        self::assertSame(2, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM closure_analysis_interval WHERE event_id = (SELECT id FROM closure_event WHERE source_group_id = :group_id)',
            ['group_id' => 'grp'],
        ));
        self::assertSame('cluster', (string) $connection->fetchOne(
            "SELECT event_type FROM closure_event WHERE hospital_id = :hospital AND event_type = 'cluster'",
            ['hospital' => $seed['hospitalId']],
        ));

        $eventId = (int) $connection->fetchOne(
            'SELECT id FROM closure_event WHERE source_group_id = :group_id',
            ['group_id' => 'grp'],
        );
        self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->rebuild();
        self::assertSame($eventId, (int) $connection->fetchOne(
            'SELECT id FROM closure_event WHERE source_group_id = :group_id',
            ['group_id' => 'grp'],
        ));
    }

    public function testSourceDeletionKeepsAnIntervalWhileAnotherSourceRemains(): void
    {
        self::bootKernel();
        $seed = $this->seed();
        $connection = self::getContainer()->get(Connection::class);
        $first = $this->insert($connection, $seed, '2026-04-01 08:00:00', '2026-04-01 09:00:00', 'emergency', 'no_bed_capacity', null, $seed['importId']);
        $second = $this->insert($connection, $seed, '2026-04-01 08:00:00', '2026-04-01 09:00:00', 'emergency', 'no_bed_capacity', null, $seed['secondImportId']);
        $rebuilder = self::getContainer()->get(ClosureAnalysisRebuilder::class);
        $rebuilder->rebuild([$seed['hospitalId']]);
        $before = (int) $connection->fetchOne('SELECT id FROM closure_event WHERE hospital_id = :hospital', ['hospital' => $seed['hospitalId']]);

        $connection->delete('closure_interval', ['id' => $first]);
        $rebuilder->rebuild([$seed['hospitalId']]);

        self::assertSame($before, (int) $connection->fetchOne(
            'SELECT id FROM closure_event WHERE hospital_id = :hospital',
            ['hospital' => $seed['hospitalId']],
        ));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_analysis_source'));

        $connection->delete('closure_interval', ['id' => $second]);
        $rebuilder->rebuild([$seed['hospitalId']]);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_analysis_interval'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_event'));
    }

    public function testOverlapAndAdjacentClosuresBecomeCandidatesWithoutMerging(): void
    {
        self::bootKernel();
        $seed = $this->seed();
        $connection = self::getContainer()->get(Connection::class);
        $this->insert($connection, $seed, '2026-05-01 08:00:00', '2026-05-01 10:00:00', 'emergency', 'no_bed_capacity', null, $seed['importId']);
        $this->insert($connection, $seed, '2026-05-01 09:00:00', '2026-05-01 11:00:00', 'emergency', 'no_bed_capacity', 'later', $seed['importId']);
        $this->insert($connection, $seed, '2026-05-01 11:00:00', '2026-05-01 12:00:00', 'emergency', 'no_bed_capacity', 'next', $seed['importId']);
        self::getContainer()->get(ClosureAnalysisRebuilder::class)->rebuild([$seed['hospitalId']]);

        $kinds = $connection->fetchFirstColumn(
            'SELECT kind FROM closure_relation_candidate WHERE hospital_id = :hospital ORDER BY kind',
            ['hospital' => $seed['hospitalId']],
        );
        self::assertSame(['adjacent', 'overlap'], $kinds);
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_event WHERE hospital_id = :hospital', ['hospital' => $seed['hospitalId']]));
    }

    public function testFailedImportIsExcludedAndAFailedPublishKeepsThePreviousProjection(): void
    {
        self::bootKernel();
        $seed = $this->seed();
        $connection = self::getContainer()->get(Connection::class);
        $connection->update('import', ['status' => ImportStatus::FAILED->value], ['id' => $seed['importId']]);
        $this->insert($connection, $seed, '2026-06-01 08:00:00', '2026-06-01 09:00:00', 'emergency', 'no_bed_capacity', null, $seed['importId']);
        $this->insert($connection, $seed, '2026-06-01 08:00:00', '2026-06-01 09:00:00', 'emergency', 'no_bed_capacity', 'kept', $seed['secondImportId']);
        self::getContainer()->get(ClosureAnalysisRebuilder::class)->rebuild([$seed['hospitalId']]);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_analysis_interval'));

        self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->rebuild();
        $live = (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_volume_hour');
        self::assertGreaterThan(0, $live);

        $rebuilder = self::getContainer()->get(ClosureVolumeProjectionRebuilder::class);
        $rebuilder->beforePublishHook = static function (): never {
            throw new \RuntimeException('publish failed');
        };
        try {
            $rebuilder->rebuild();
            self::fail('The publish hook should abort the rebuild.');
        } catch (\RuntimeException $exception) {
            self::assertSame('publish failed', $exception->getMessage());
        }
        self::assertSame($live, (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_volume_hour'));
        self::assertFalse($connection->createSchemaManager()->tablesExist(['closure_volume_hour_build']));
        self::assertLessThan(256 * 1024 * 1024, self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->maxPartitionBytes());
    }

    public function testASecondWeekDoesNotRaiseThePartitionMemory(): void
    {
        self::bootKernel();
        $seed = $this->seed();
        $connection = self::getContainer()->get(Connection::class);
        foreach (['2026-06-01', '2026-06-08'] as $day) {
            for ($index = 0; $index < 4; ++$index) {
                $this->insert(
                    $connection,
                    $seed,
                    $day.' 0'.$index.':00:00',
                    $day.' 0'.($index + 1).':00:00',
                    'emergency',
                    'no_bed_capacity',
                    'week-'.$day.'-'.$index,
                    $seed['importId'],
                );
            }
        }

        $rebuilder = self::getContainer()->get(ClosureVolumeProjectionRebuilder::class);
        self::getContainer()->get(ClosureAnalysisRebuilder::class)->rebuild([$seed['hospitalId']]);
        $rebuilder->rebuild(null, [$seed['hospitalId']]);

        self::assertCount(2, $rebuilder->partitionSamples);
        self::assertLessThan(256 * 1024 * 1024, $rebuilder->maxPartitionBytes);
        self::assertLessThanOrEqual(
            (int) ($rebuilder->partitionSamples[0] * 1.5) + (16 * 1024 * 1024),
            $rebuilder->partitionSamples[1],
        );
    }

    /**
     * @return array{hospitalId: int, departmentId: int, specialityId: int, importId: int, secondImportId: int}
     */
    private function seed(): array
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
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'status' => ImportStatus::COMPLETED,
        ]);
        $second = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'status' => ImportStatus::COMPLETED,
        ]);

        return [
            'hospitalId' => (int) $hospital->getId(),
            'departmentId' => (int) $department->getId(),
            'specialityId' => (int) $speciality->getId(),
            'importId' => (int) $import->getId(),
            'secondImportId' => (int) $second->getId(),
        ];
    }

    /**
     * @param array{hospitalId: int, departmentId: int, specialityId: int, importId: int, secondImportId: int} $seed
     */
    private function insert(
        Connection $connection,
        array $seed,
        string $startsAt,
        string $endsAt,
        string $careLevel,
        string $reason,
        ?string $groupId,
        int $importId,
        ?int $departmentId = null,
    ): int {
        $connection->insert('closure_interval', [
            'hospital_id' => $seed['hospitalId'],
            'import_id' => $importId,
            'speciality_id' => $seed['specialityId'],
            'department_id' => $departmentId ?? $seed['departmentId'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'care_level' => $careLevel,
            'reason' => $reason,
            'facility_kind' => 'clinic',
            'closure_unit' => 'unit',
            'source_group_id' => $groupId,
            'source_recorded_at' => $startsAt,
            'source_changed_at' => $startsAt,
        ]);

        return (int) $connection->lastInsertId();
    }
}
