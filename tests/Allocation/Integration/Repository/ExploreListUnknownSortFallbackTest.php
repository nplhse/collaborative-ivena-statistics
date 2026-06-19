<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Repository;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Query\ListAllocationsQuery;
use App\Allocation\Infrastructure\Repository\AssignmentRepository;
use App\Allocation\Infrastructure\Repository\DepartmentRepository;
use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\Allocation\Infrastructure\Repository\InfectionRepository;
use App\Allocation\Infrastructure\Repository\MciCaseRepository;
use App\Allocation\Infrastructure\Repository\OccasionRepository;
use App\Allocation\Infrastructure\Repository\SecondaryTransportRepository;
use App\Allocation\Infrastructure\Repository\SpecialityRepository;
use App\Allocation\UI\Http\DTO\AllocationQueryParametersDTO;
use App\Allocation\UI\Http\DTO\AreaListQueryParametersDTO;
use App\Allocation\UI\Http\DTO\AssignmentQueryParametersDTO;
use App\Allocation\UI\Http\DTO\HospitalQueryParametersDTO;
use App\Allocation\UI\Http\DTO\IndicationQueryParametersDTO;
use App\Allocation\UI\Http\DTO\InfectionQueryParametersDTO;
use App\Allocation\UI\Http\DTO\MciCaseQueryParametersDTO;
use App\Allocation\UI\Http\DTO\OccasionQueryParametersDTO;
use App\Allocation\UI\Http\DTO\SecondaryTransportQueryParametersDTO;
use App\Allocation\UI\Http\DTO\SpecialityQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ExploreListUnknownSortFallbackTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testHospitalListPaginatorFallsBackToDefaultSortField(): void
    {
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['name' => 'Zebra Hospital']);
        HospitalFactory::createOne(['name' => 'Alpha Hospital']);

        $paginator = self::getContainer()
            ->get(HospitalRepository::class)
            ->getHospitalListPaginator(new HospitalQueryParametersDTO(orderBy: 'asc', sortBy: 'unknown'));

        self::assertSame(2, $paginator->getNumResults());
    }

    public function testAssignmentListPaginatorFallsBackToDefaultSortField(): void
    {
        $paginator = self::getContainer()
            ->get(AssignmentRepository::class)
            ->getListPaginator(new AssignmentQueryParametersDTO(sortBy: 'unknown'));

        self::assertSame(0, $paginator->getNumResults());
    }

    public function testDepartmentListPaginatorFallsBackToDefaultSortField(): void
    {
        $paginator = self::getContainer()
            ->get(DepartmentRepository::class)
            ->getListPaginator(new SpecialityQueryParametersDTO(sortBy: 'unknown'));

        self::assertSame(0, $paginator->getNumResults());
    }

    public function testDispatchAreaListPaginatorFallsBackToDefaultSortField(): void
    {
        $paginator = self::getContainer()
            ->get(DispatchAreaRepository::class)
            ->getAreaListPaginator(new AreaListQueryParametersDTO(sortBy: 'unknown'));

        self::assertSame(0, $paginator->getNumResults());
    }

    public function testIndicationNormalizedListPaginatorFallsBackToDefaultSortField(): void
    {
        $paginator = self::getContainer()
            ->get(IndicationNormalizedRepository::class)
            ->getListPaginator(new IndicationQueryParametersDTO(sortBy: 'unknown'));

        self::assertSame(0, $paginator->getNumResults());
    }

    public function testIndicationRawListPaginatorFallsBackToDefaultSortField(): void
    {
        $paginator = self::getContainer()
            ->get(IndicationRawRepository::class)
            ->getListPaginator(new IndicationQueryParametersDTO(sortBy: 'unknown', type: 'raw'));

        self::assertSame(0, $paginator->getNumResults());
    }

    public function testInfectionListPaginatorFallsBackToDefaultSortField(): void
    {
        $paginator = self::getContainer()
            ->get(InfectionRepository::class)
            ->getListPaginator(new InfectionQueryParametersDTO(sortBy: 'unknown'));

        self::assertSame(0, $paginator->getNumResults());
    }

    public function testMciCaseListPaginatorFallsBackToDefaultSortField(): void
    {
        $paginator = self::getContainer()
            ->get(MciCaseRepository::class)
            ->getListPaginator(new MciCaseQueryParametersDTO(sortBy: 'unknown'));

        self::assertSame(0, $paginator->getNumResults());
    }

    public function testOccasionListPaginatorFallsBackToDefaultSortField(): void
    {
        $paginator = self::getContainer()
            ->get(OccasionRepository::class)
            ->getListPaginator(new OccasionQueryParametersDTO(sortBy: 'unknown'));

        self::assertSame(0, $paginator->getNumResults());
    }

    public function testSecondaryTransportListPaginatorFallsBackToDefaultSortField(): void
    {
        $paginator = self::getContainer()
            ->get(SecondaryTransportRepository::class)
            ->getListPaginator(new SecondaryTransportQueryParametersDTO(sortBy: 'unknown'));

        self::assertSame(0, $paginator->getNumResults());
    }

    public function testSpecialityListPaginatorFallsBackToDefaultSortField(): void
    {
        $paginator = self::getContainer()
            ->get(SpecialityRepository::class)
            ->getListPaginator(new SpecialityQueryParametersDTO(sortBy: 'unknown'));

        self::assertSame(0, $paginator->getNumResults());
    }

    public function testAllocationListQueryFallsBackToDefaultSortField(): void
    {
        $paginator = self::getContainer()
            ->get(ListAllocationsQuery::class)
            ->getPaginator(new AllocationQueryParametersDTO(sortBy: 'unknown'));

        self::assertCount(0, iterator_to_array($paginator->getResults()));
    }
}
