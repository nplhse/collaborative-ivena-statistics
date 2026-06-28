<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Query\ListAllocationsQuery;
use App\Allocation\UI\Http\DTO\AllocationQueryParametersDTO;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ListAllocationsAssignmentOccasionDepartmentWasClosedFilterTest extends KernelTestCase
{
    use Factories;

    private ListAllocationsQuery $query;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(ListAllocationsQuery::class);
    }

    public function testFiltersByAssignmentOccasionAndDepartmentWasClosed(): void
    {
        $shared = $this->seedAllocationGraph();

        $assignmentA = AssignmentFactory::createOne(['name' => 'Assignment A']);
        $assignmentB = AssignmentFactory::createOne(['name' => 'Assignment B']);
        $occasionA = OccasionFactory::createOne(['name' => 'Occasion A']);
        $occasionB = OccasionFactory::createOne(['name' => 'Occasion B']);

        AllocationFactory::createOne([
            'assignment' => $assignmentA,
            'occasion' => $occasionA,
            'departmentWasClosed' => true,
        ] + $shared);
        AllocationFactory::createOne([
            'assignment' => $assignmentB,
            'occasion' => $occasionB,
            'departmentWasClosed' => false,
        ] + $shared);

        $assignmentResults = iterator_to_array($this->query->getPaginator(new AllocationQueryParametersDTO(
            assignment: (int) $assignmentA->getId(),
        ))->getResults());
        self::assertCount(1, $assignmentResults);

        $occasionResults = iterator_to_array($this->query->getPaginator(new AllocationQueryParametersDTO(
            occasion: (int) $occasionA->getId(),
        ))->getResults());
        self::assertCount(1, $occasionResults);

        $closedResults = iterator_to_array($this->query->getPaginator(new AllocationQueryParametersDTO(
            departmentWasClosed: 1,
        ))->getResults());
        self::assertCount(1, $closedResults);
        self::assertTrue($closedResults[0]['departmentWasClosed']);

        $combinedResults = iterator_to_array($this->query->getPaginator(new AllocationQueryParametersDTO(
            assignment: (int) $assignmentA->getId(),
            occasion: (int) $occasionA->getId(),
            departmentWasClosed: 1,
        ))->getResults());
        self::assertCount(1, $combinedResults);
    }

    /**
     * @return array<string, object>
     */
    private function seedAllocationGraph(): array
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatchArea]);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        IndicationRawFactory::createOne();

        return [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ];
    }
}
