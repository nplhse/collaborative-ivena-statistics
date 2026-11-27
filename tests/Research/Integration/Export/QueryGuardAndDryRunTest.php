<?php

declare(strict_types=1);

namespace App\Tests\Research\Integration\Export;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Research\Application\Export\QueryViolation;
use App\Research\Domain\Enum\QueryKind;
use App\Research\Infrastructure\Export\ExportConnection;
use App\Research\Infrastructure\Export\ExportRunner;
use App\Research\Infrastructure\Export\QueryDryRunner;
use App\Research\Infrastructure\Export\QueryGuard;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The allowlist check and the dry run on the export connection against real EXPLAIN plans and a small fixture (ADR 045):
 * four urban hospitals with one allocation each and one rural hospital with two.
 */
#[ResetDatabase]
final class QueryGuardAndDryRunTest extends KernelTestCase
{
    use Factories;

    private const string BY_LOCATION = 'SELECT h.location, count(*) AS allocations, array_agg(DISTINCT a.hospital_id) AS _hospital_ids FROM allocation a JOIN hospital h ON h.id = a.hospital_id GROUP BY h.location ORDER BY h.location';

    public function testAnAllowedQueryPassesAndItsDryRunFindsTheSmallGroup(): void
    {
        $this->seed();

        self::assertSame([], $this->guard()->check(self::BY_LOCATION, QueryKind::Aggregated));

        $run = self::getContainer()->get(QueryDryRunner::class)->run(self::BY_LOCATION, QueryKind::Aggregated);

        self::assertNull($run->error);
        self::assertSame(['location', 'allocations'], $run->columns, 'the reserved column never leaves the runner');
        self::assertSame(2, $run->rows);
        self::assertSame(5, $run->hospitals);
        // Rural: one hospital (below 3); Urban: four hospitals.
        self::assertSame(1, $run->belowMinimum);
        self::assertSame([['location' => 'Rural', 'allocations' => 2]], $run->belowMinimumRows);
    }

    public function testBlockedColumnsOtherTablesAndInternalIdsAreRefused(): void
    {
        self::assertSame(['column'], $this->codes("SELECT count(*) AS n, array_agg(DISTINCT a.hospital_id) AS _hospital_ids FROM allocation a WHERE a.notes LIKE '%x%'"));
        self::assertSame(['table', 'reserved_missing'], $this->codes('SELECT username FROM "user"'));
        self::assertSame(['internal_output'], $this->codes('SELECT a.hospital_id, count(*) AS n, array_agg(DISTINCT a.hospital_id) AS _hospital_ids FROM allocation a GROUP BY a.hospital_id'));
        self::assertSame(['column'], $this->codes('SELECT h.name, count(*) AS n, array_agg(DISTINCT a.hospital_id) AS _hospital_ids FROM allocation a JOIN hospital h ON h.id = a.hospital_id GROUP BY h.name'));
        self::assertSame(['denied_function'], $this->codes("SELECT query_to_xml('select 1', true, true, '') AS x"));
        self::assertSame(['sql_error'], $this->codes('SELECT no_such_column FROM allocation'));
    }

    public function testTheReservedColumnIsCheckedAgainstTheRealPlan(): void
    {
        // #1692: the runner counts contributing hospitals from _hospital_ids, so it must be the real list of the group.
        self::assertSame(['reserved_not_derived'], $this->codes('SELECT a.urgency, count(*) AS n, ARRAY[1,2,3] AS _hospital_ids FROM allocation a GROUP BY a.urgency'));
        self::assertSame(['reserved_not_derived'], $this->codes('SELECT a.urgency, count(*) AS n, array_agg(a.hospital_id) AS _hospital_ids FROM allocation a GROUP BY a.urgency'));
        self::assertSame(['reserved_not_derived'], $this->codes('SELECT x.urgency, x.n, x.ids AS _hospital_ids FROM (SELECT a.urgency, count(*) AS n, array_agg(DISTINCT a.hospital_id) AS ids FROM allocation a GROUP BY a.urgency, a.hospital_id) x'));
        self::assertSame(['reserved_not_derived'], $this->codes('WITH a AS MATERIALIZED (SELECT a.urgency, a.hospital_id FROM allocation a) SELECT a.urgency, count(*) AS n, array_agg(DISTINCT a.hospital_id) AS _hospital_ids FROM a GROUP BY a.urgency'));
        self::assertSame(['hospital_dependent_output'], $this->codes('SELECT h.location, count(*) FILTER (WHERE h.id = 3) AS n, array_agg(DISTINCT a.hospital_id) AS _hospital_ids FROM allocation a JOIN hospital h ON h.id = a.hospital_id GROUP BY h.location'));

        // Accepted: ORDER BY and LIMIT above the grouping, a WHERE on hospitals, an inlined CTE.
        self::assertSame([], $this->codes(self::BY_LOCATION.' LIMIT 5'));
        self::assertSame([], $this->codes('SELECT a.urgency, count(*) AS n, array_agg(DISTINCT a.hospital_id) AS _hospital_ids FROM allocation a WHERE a.hospital_id IN (1, 2, 3) GROUP BY a.urgency'));
        self::assertSame([], $this->codes('WITH x AS (SELECT a.urgency, a.hospital_id FROM allocation a) SELECT x.urgency, count(*) AS n, array_agg(DISTINCT x.hospital_id) AS _hospital_ids FROM x GROUP BY x.urgency'));
    }

    public function testTheDryRunCannotWrite(): void
    {
        $run = self::getContainer()->get(QueryDryRunner::class)->run("SELECT nextval('allocation_id_seq') AS n", QueryKind::Aggregated);

        self::assertNotNull($run->error);
        self::assertStringContainsString('read-only transaction', (string) $run->error);
        self::assertSame(1, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT 1'), 'the test connection still works after the rollback');
    }

    /**
     * @return list<string>
     */
    private function codes(string $sql): array
    {
        return array_map(static fn (QueryViolation $violation): string => $violation->code, $this->guard()->check($sql, QueryKind::Aggregated));
    }

    private function guard(): QueryGuard
    {
        return self::getContainer()->get(QueryGuard::class);
    }

    private function seed(): void
    {
        $owner = UserFactory::createOne();
        $state = StateFactory::createOne(['name' => 'Testland']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'Testleitstelle', 'state' => $state]);
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne(['code' => 999001]);

        $hospital = static fn (HospitalLocation $location): Hospital => HospitalFactory::createOne([
            'state' => $state, 'dispatchArea' => $dispatchArea, 'tier' => HospitalTier::FULL, 'location' => $location, 'owner' => $owner,
        ]);
        foreach ([HospitalLocation::URBAN, HospitalLocation::URBAN, HospitalLocation::URBAN, HospitalLocation::URBAN, HospitalLocation::RURAL] as $location) {
            $current = $hospital($location);
            $import = ImportFactory::createOne(['hospital' => $current, 'createdBy' => $owner]);
            foreach (range(1, HospitalLocation::RURAL === $location ? 2 : 1) as $ignored) {
                AllocationFactory::createOne(['hospital' => $current, 'import' => $import, 'state' => $state, 'dispatchArea' => $dispatchArea]);
            }
        }
    }

    public function testAWritableConnectionIsRefusedOutsideTheTestEnvironment(): void
    {
        // The test environment shares the application user and accepts it (RESEARCH_EXPECT_READ_ONLY=0); production does not.
        $connection = new ExportConnection(self::getContainer()->get(ManagerRegistry::class), 'configured', null);
        $clock = self::getContainer()->get(ClockInterface::class);

        self::assertSame(['connection_writable'], array_map(static fn (QueryViolation $violation): string => $violation->code, new QueryGuard($connection)->check(self::BY_LOCATION, QueryKind::Aggregated)));
        self::assertSame('connection_writable', new QueryDryRunner($connection, $clock)->run(self::BY_LOCATION, QueryKind::Aggregated)->error);
        self::assertSame('connection_writable', new ExportRunner($connection, $clock)->run([], sys_get_temp_dir())->error);
    }
}
