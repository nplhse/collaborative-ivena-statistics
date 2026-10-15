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
use App\Import\Application\Service\ImportExportPeriodDeriver;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\ClosureAnalytics\Infrastructure\Projection\ClosureAnalysisRebuilder;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A closure that IVENA cuts at the end of one monthly export and continues in the next
 * must be marked as export_boundary once both closure imports have derived periods.
 */
#[ResetDatabase]
final class ClosureExportBoundaryTest extends KernelTestCase
{
    use Factories;

    /**
     * @return iterable<string, array{bool}>
     */
    public static function importOrders(): iterable
    {
        yield 'March imported first' => [false];
        yield 'April imported first' => [true];
    }

    #[DataProvider('importOrders')]
    public function testAClosureCutAtTheExportBoundaryIsDetected(bool $aprilFirst): void
    {
        self::bootKernel();
        $seed = $this->seed($aprilFirst);
        $this->derivePeriods($seed);

        $connection = $this->connection();
        self::assertSame(
            [
                [(int) $seed['marchImportId'], '2026-03-01 00:00:00', '2026-04-01 00:00:00'],
                [(int) $seed['aprilImportId'], '2026-04-01 00:00:00', '2026-05-01 00:00:00'],
            ],
            array_map(
                static fn (array $row): array => [(int) $row['id'], $row['export_starts_at'], $row['export_ends_at']],
                $connection->fetchAllAssociative(
                    'SELECT id, export_starts_at, export_ends_at FROM import WHERE id IN (?, ?) ORDER BY export_starts_at',
                    [$seed['marchImportId'], $seed['aprilImportId']],
                ),
            ),
            'Both closure periods are widened to the months covered by allocations.',
        );

        self::getContainer()->get(ClosureAnalysisRebuilder::class)->rebuild([$seed['hospitalId']]);

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_event WHERE hospital_id = ?', [$seed['hospitalId']]));
        self::assertSame(
            ['export_boundary'],
            $connection->fetchFirstColumn('SELECT kind FROM closure_relation_candidate WHERE hospital_id = ?', [$seed['hospitalId']]),
        );
    }

    public function testWithoutDerivedPeriodsTheSameClosuresAreOnlyAdjacent(): void
    {
        self::bootKernel();
        $seed = $this->seed(false);

        self::getContainer()->get(ClosureAnalysisRebuilder::class)->rebuild([$seed['hospitalId']]);

        self::assertSame(
            ['adjacent'],
            $this->connection()->fetchFirstColumn('SELECT kind FROM closure_relation_candidate WHERE hospital_id = ?', [$seed['hospitalId']]),
        );
    }

    /**
     * @param array{hospitalId: int, allocationImportIds: list<int>, marchImportId: int, aprilImportId: int} $seed
     */
    private function derivePeriods(array $seed): void
    {
        $deriver = self::getContainer()->get(ImportExportPeriodDeriver::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($seed['allocationImportIds'] as $importId) {
            $import = $entityManager->find(Import::class, $importId);
            self::assertInstanceOf(Import::class, $import);
            $deriver->apply($import);
        }
        $entityManager->flush();
        self::assertSame(2, $deriver->refreshClosureImports($seed['hospitalId']));
        $entityManager->clear();
    }

    /**
     * @return array{hospitalId: int, allocationImportIds: list<int>, marchImportId: int, aprilImportId: int}
     */
    private function seed(bool $aprilFirst): array
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatch, 'owner' => $user, 'createdBy' => $user]);
        $speciality = SpecialityFactory::createOne();
        $department = DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne(['code' => random_int(900, 999)]);
        IndicationNormalizedFactory::createOne();

        $allocationImportIds = [];
        foreach ([['2026-03-01 00:20:00', '2026-03-31 23:40:00'], ['2026-04-01 00:10:00', '2026-04-30 23:50:00']] as $month) {
            $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user, 'type' => ImportType::ALLOCATION, 'status' => ImportStatus::COMPLETED]);
            $allocationImportIds[] = (int) $import->getId();
            foreach ($month as $createdAt) {
                AllocationFactory::createOne([
                    'import' => $import,
                    'hospital' => $hospital,
                    'state' => $state,
                    'dispatchArea' => $dispatch,
                    'speciality' => $speciality,
                    'department' => $department,
                    'createdAt' => new \DateTimeImmutable($createdAt),
                    'arrivalAt' => new \DateTimeImmutable($createdAt)->modify('+20 minutes'),
                    'urgency' => AllocationUrgency::EMERGENCY,
                ]);
            }
        }

        $closures = [
            'march' => ['2026-03-20 08:00:00', '2026-04-01 00:00:00', 'march-group'],
            'april' => ['2026-04-01 00:00:00', '2026-04-05 12:00:00', 'april-group'],
        ];
        $importIds = [];
        foreach ($aprilFirst ? ['april', 'march'] : ['march', 'april'] as $month) {
            [$startsAt, $endsAt, $groupId] = $closures[$month];
            $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user, 'type' => ImportType::CLOSURE, 'status' => ImportStatus::COMPLETED]);
            $importIds[$month] = (int) $import->getId();
            $this->connection()->insert('closure_interval', [
                'hospital_id' => $hospital->getId(),
                'import_id' => $importIds[$month],
                'speciality_id' => $speciality->getId(),
                'department_id' => $department->getId(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'care_level' => 'emergency',
                'reason' => 'no_bed_capacity',
                'facility_kind' => 'clinic',
                'closure_unit' => 'boundary unit',
                'source_group_id' => $groupId,
                'source_recorded_at' => $startsAt,
                'source_changed_at' => $startsAt,
            ]);
        }

        return [
            'hospitalId' => (int) $hospital->getId(),
            'allocationImportIds' => $allocationImportIds,
            'marchImportId' => $importIds['march'],
            'aprilImportId' => $importIds['april'],
        ];
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
