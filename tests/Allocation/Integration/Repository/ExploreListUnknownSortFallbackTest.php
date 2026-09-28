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
use App\Tests\Support\Pagination\PaginatesQueries;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ExploreListUnknownSortFallbackTest extends KernelTestCase
{
    use Factories;
    use PaginatesQueries;

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

        $page = $this->paginateQuery(self::getContainer()
            ->get(HospitalRepository::class)
            ->hospitalListQuery(new HospitalQueryParametersDTO(orderBy: 'asc', sortBy: 'unknown')));

        self::assertSame(2, $page->getTotalItems());
        self::assertCount(2, $page->getItems());
    }

    public function testAssignmentListPaginatorFallsBackToDefaultSortField(): void
    {
        $page = $this->paginateQuery(self::getContainer()
            ->get(AssignmentRepository::class)
            ->listQuery(new AssignmentQueryParametersDTO(sortBy: 'unknown')));

        self::assertSame(0, $page->getTotalItems());
        self::assertSame([], $page->getItems());
    }

    public function testDepartmentListPaginatorFallsBackToDefaultSortField(): void
    {
        $page = $this->paginateQuery(self::getContainer()
            ->get(DepartmentRepository::class)
            ->listQuery(new SpecialityQueryParametersDTO(sortBy: 'unknown')));

        self::assertSame(0, $page->getTotalItems());
        self::assertSame([], $page->getItems());
    }

    public function testDispatchAreaListPaginatorFallsBackToDefaultSortField(): void
    {
        $page = $this->paginateQuery(self::getContainer()
            ->get(DispatchAreaRepository::class)
            ->areaListQuery(new AreaListQueryParametersDTO(sortBy: 'unknown')));

        self::assertSame(0, $page->getTotalItems());
        self::assertSame([], $page->getItems());
    }

    public function testIndicationNormalizedListPaginatorFallsBackToDefaultSortField(): void
    {
        $page = $this->paginateQuery(self::getContainer()
            ->get(IndicationNormalizedRepository::class)
            ->listQuery(new IndicationQueryParametersDTO(sortBy: 'unknown')));

        self::assertSame(0, $page->getTotalItems());
        self::assertSame([], $page->getItems());
    }

    public function testIndicationRawListPaginatorFallsBackToDefaultSortField(): void
    {
        $page = $this->paginateQuery(self::getContainer()
            ->get(IndicationRawRepository::class)
            ->listQuery(new IndicationQueryParametersDTO(sortBy: 'unknown', type: 'raw')));

        self::assertSame(0, $page->getTotalItems());
        self::assertSame([], $page->getItems());
    }

    public function testInfectionListPaginatorFallsBackToDefaultSortField(): void
    {
        $page = $this->paginateQuery(self::getContainer()
            ->get(InfectionRepository::class)
            ->listQuery(new InfectionQueryParametersDTO(sortBy: 'unknown')));

        self::assertSame(0, $page->getTotalItems());
        self::assertSame([], $page->getItems());
    }

    public function testMciCaseListPaginatorFallsBackToDefaultSortField(): void
    {
        $page = $this->paginateQuery(self::getContainer()
            ->get(MciCaseRepository::class)
            ->listQuery(new MciCaseQueryParametersDTO(sortBy: 'unknown')));

        self::assertSame(0, $page->getTotalItems());
        self::assertSame([], $page->getItems());
    }

    public function testOccasionListPaginatorFallsBackToDefaultSortField(): void
    {
        $page = $this->paginateQuery(self::getContainer()
            ->get(OccasionRepository::class)
            ->listQuery(new OccasionQueryParametersDTO(sortBy: 'unknown')));

        self::assertSame(0, $page->getTotalItems());
        self::assertSame([], $page->getItems());
    }

    public function testSecondaryTransportListPaginatorFallsBackToDefaultSortField(): void
    {
        $page = $this->paginateQuery(self::getContainer()
            ->get(SecondaryTransportRepository::class)
            ->listQuery(new SecondaryTransportQueryParametersDTO(sortBy: 'unknown')));

        self::assertSame(0, $page->getTotalItems());
        self::assertSame([], $page->getItems());
    }

    public function testSpecialityListPaginatorFallsBackToDefaultSortField(): void
    {
        $page = $this->paginateQuery(self::getContainer()
            ->get(SpecialityRepository::class)
            ->listQuery(new SpecialityQueryParametersDTO(sortBy: 'unknown')));

        self::assertSame(0, $page->getTotalItems());
        self::assertSame([], $page->getItems());
    }

    public function testAllocationListQueryFallsBackToDefaultSortField(): void
    {
        $paginator = self::getContainer()
            ->get(ListAllocationsQuery::class)
            ->getPaginator(new AllocationQueryParametersDTO(sortBy: 'unknown'));

        self::assertCount(0, iterator_to_array($paginator->getResults()));
    }
}
