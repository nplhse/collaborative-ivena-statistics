<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Allocations;

use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use Symfony\Component\HttpFoundation\Request;

final class ListAllocationsFiltersTest extends ListAllocationsControllerTestCase
{
    public function testIsInfectiousAndInfectionFiltersOnlyReturnMatchingAllocations(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();

        $targetInfection = InfectionFactory::createOne(['name' => 'Influenza']);
        $otherInfection = InfectionFactory::createOne(['name' => 'Norovirus']);

        $matchingAllocation = AllocationFactory::createOne(['infection' => $targetInfection]);
        AllocationFactory::createOne(['infection' => $otherInfection]);
        AllocationFactory::createOne(['infection' => null]);

        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf(
                '/explore/allocation?isInfectious=1&infection=%d&limit=50',
                $targetInfection->getId()
            )
        );

        self::assertResponseIsSuccessful();
        $ids = $this->extractAllocationIds($crawler);
        self::assertSame([$matchingAllocation->getPublicIdString()], $ids);
    }

    public function testIsVentilatedFilterOnlyReturnsVentilatedAllocations(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();

        $ventilatedAllocation = AllocationFactory::createOne(['isVentilated' => true]);
        AllocationFactory::createOne(['isVentilated' => false]);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/explore/allocation?isVentilated=1&limit=50'
        );

        self::assertResponseIsSuccessful();
        $ids = $this->extractAllocationIds($crawler);
        self::assertSame([$ventilatedAllocation->getPublicIdString()], $ids);
    }

    public function testDepartmentSpecialityAndTransportTypeFilters(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();

        $department = DepartmentFactory::find(['name' => 'Kardiologie']);
        $speciality = SpecialityFactory::find(['name' => 'Innere Medizin']);

        $matchingAllocation = AllocationFactory::createOne([
            'department' => $department,
            'speciality' => $speciality,
            'transportType' => AllocationTransportType::GROUND,
        ]);
        AllocationFactory::createOne([
            'transportType' => AllocationTransportType::AIR,
        ]);

        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf(
                '/explore/allocation?department=%d&speciality=%d&transportType=G&limit=50',
                $department->getId(),
                $speciality->getId(),
            )
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#allocation-filter-drawer-accordion');
        self::assertSelectorExists('[data-testid="allocation-filters-drawer"]');
        self::assertSelectorExists('[data-testid="allocation-filter-section-geography"]');
        self::assertSelectorExists('#allocation-filters .offcanvas-footer');
        self::assertSelectorExists('[data-testid="allocation-filters-cancel"]');
        self::assertSelectorExists('[data-testid="allocation-filters-apply"]');
        self::assertSelectorExists('[data-testid="allocation-filters-reset"].btn-outline-secondary');
        $ids = $this->extractAllocationIds($crawler);
        self::assertSame([$matchingAllocation->getPublicIdString()], $ids);
    }

    public function testAssignmentOccasionAndDepartmentWasClosedFilters(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();

        $assignment = AssignmentFactory::find(['name' => 'Test Assignment']);
        $occasion = OccasionFactory::find(['name' => 'Test Occasion']);

        $matchingAllocation = AllocationFactory::createOne([
            'assignment' => $assignment,
            'occasion' => $occasion,
            'departmentWasClosed' => true,
        ]);
        AllocationFactory::createOne([
            'departmentWasClosed' => false,
        ]);

        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf(
                '/explore/allocation?assignment=%d&occasion=%d&departmentWasClosed=1&limit=50',
                $assignment->getId(),
                $occasion->getId(),
            )
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="assignment"]');
        self::assertSelectorExists('select[name="occasion"]');
        self::assertSelectorExists('[data-testid="allocation-filter-department-was-closed"]');
        $ids = $this->extractAllocationIds($crawler);
        self::assertSame([$matchingAllocation->getPublicIdString()], $ids);
        self::assertSelectorExists('.alert.alert-info');
        self::assertSelectorTextContains('.alert.alert-info', 'Test Assignment');
        self::assertSelectorTextContains('.alert.alert-info', 'Test Occasion');
    }

    public function testDepartmentWasClosedAllocationShowsRowHighlightAndIndicator(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();

        AllocationFactory::createOne(['departmentWasClosed' => true]);
        AllocationFactory::createOne(['departmentWasClosed' => false]);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/explore/allocation?limit=50'
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('tr.allocation-row-department-closed');
        self::assertSelectorExists('[data-testid="department-was-closed-indicator"]');
        self::assertCount(1, $crawler->filter('tr.allocation-row-department-closed'));
        self::assertCount(
            0,
            $crawler->filter('[data-testid="department-was-closed-indicator"] .w-3.h-3 + *'),
            'Properties indicator should render icon only without trailing text.',
        );
    }
}
