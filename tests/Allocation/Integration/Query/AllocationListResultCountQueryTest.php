<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

use App\Allocation\Application\ListExtension\AllocationListExtensionRegistry;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Query\AllocationListResultCountQuery;
use App\Allocation\Infrastructure\Query\ListAllocationsQuery;
use App\Allocation\UI\Http\DTO\AllocationQueryParametersDTO;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Shared\Infrastructure\Pagination\ResultCount;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Result count of the Explore allocation list (#1432).
 */
#[ResetDatabase]
final class AllocationListResultCountQueryTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        UserFactory::createOne();
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        OccasionFactory::createOne();
        SecondaryTransportFactory::createOne();
        InfectionFactory::createOne();
        IndicationRawFactory::createOne();
        IndicationNormalizedFactory::createOne();
    }

    public function testMyHospitalsWithSeveralHospitalsGetsAnExactCount(): void
    {
        // 3 rows in hospital A and 2 in hospital B belong to the user; 4 rows of another hospital do not.
        $owner = $this->seedThreeHospitals([3, 2, 4]);

        $paginator = $this->listQuery()->getPaginator(
            new AllocationQueryParametersDTO(limit: 2, hospitalFilter: 'my_hospitals'),
            $owner,
        );

        self::assertCount(2, $paginator->getItems());
        self::assertEquals(ResultCount::exact(5), $paginator->getResultCount());
        self::assertSame(3, $paginator->getLastPage());
    }

    public function testTheClosureFilterGetsACountToo(): void
    {
        $owner = $this->seedThreeHospitals([1, 1, 1]);

        // No closure imports: every allocation is "unknown" (closure-architecture.md). The third hospital belongs to
        // someone else, so the owner counts only their own two (#1566).
        $paginator = $this->listQuery()->getPaginator(new AllocationQueryParametersDTO(closureAtAssignment: 'unknown'), $owner);

        self::assertEquals(ResultCount::exact(2), $paginator->getResultCount());
    }

    public function testANewAllocationChangesTheCachedCount(): void
    {
        $owner = $this->seedThreeHospitals([2, 0, 0]);
        $query = new AllocationQueryParametersDTO();

        self::assertEquals(ResultCount::exact(2), $this->listQuery()->getPaginator($query, $owner)->getResultCount());

        // In one of the owner's hospitals: the owner counts only their own (#1566).
        AllocationFactory::createOne(['hospital' => HospitalFactory::findBy(['owner' => $owner])[0]]);

        self::assertEquals(ResultCount::exact(3), $this->listQuery()->getPaginator($query, $owner)->getResultCount());
    }

    public function testATimedOutCountFallsBackToALowerBoundAndRestoresTheTimeout(): void
    {
        $this->seedThreeHospitals([30, 0, 0]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $before = $connection->fetchOne('SHOW statement_timeout');

        // 30^5 = 24.3 million combinations cannot be counted in 20 ms; their first 10,001 rows can.
        $slow = $entityManager->createQueryBuilder()->select('a.id')->from(Allocation::class, 'a');
        foreach (['b', 'c', 'd', 'e'] as $alias) {
            $slow->from(Allocation::class, $alias);
        }

        $count = new AllocationListResultCountQuery($entityManager, new ArrayAdapter(), new NullLogger(), new AllocationListExtensionRegistry([]), 20)->count($slow);

        self::assertEquals(ResultCount::moreThan(AllocationListResultCountQuery::EXACT_LIMIT), $count);
        self::assertSame($before, $connection->fetchOne('SHOW statement_timeout'));
        self::assertSame(30, (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation'));
    }

    /**
     * @param array{int, int, int} $rows allocations of two hospitals owned by the returned user, and of a third one
     */
    private function seedThreeHospitals(array $rows): User
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $other = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $owner]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $owner, 'state' => $state]);

        foreach ([$owner, $owner, $other] as $index => $hospitalOwner) {
            $hospital = HospitalFactory::createOne([
                'createdBy' => $hospitalOwner,
                'owner' => $hospitalOwner,
                'dispatchArea' => $dispatchArea,
                'state' => $state,
            ]);
            $import = ImportFactory::createOne(['hospital' => $hospital]);
            if ($rows[$index] > 0) {
                AllocationFactory::createMany($rows[$index], ['hospital' => $hospital, 'import' => $import]);
            }
        }

        return $owner;
    }

    private function listQuery(): ListAllocationsQuery
    {
        return self::getContainer()->get(ListAllocationsQuery::class);
    }
}
