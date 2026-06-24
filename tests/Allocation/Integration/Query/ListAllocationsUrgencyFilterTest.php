<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
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
final class ListAllocationsUrgencyFilterTest extends KernelTestCase
{
    use Factories;

    private ListAllocationsQuery $query;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(ListAllocationsQuery::class);
    }

    public function testFiltersAllocationsByUrgency(): void
    {
        $shared = $this->seedAllocationGraph();

        AllocationFactory::createOne(['urgency' => AllocationUrgency::EMERGENCY] + $shared);
        AllocationFactory::createOne(['urgency' => AllocationUrgency::INPATIENT] + $shared);
        AllocationFactory::createOne(['urgency' => AllocationUrgency::OUTPATIENT] + $shared);

        $paginator = $this->query->getPaginator(new AllocationQueryParametersDTO(
            urgency: (string) AllocationUrgency::INPATIENT->value,
        ));

        self::assertCount(1, iterator_to_array($paginator->getResults()));
    }

    public function testIgnoresInvalidUrgencyFilter(): void
    {
        $shared = $this->seedAllocationGraph();

        AllocationFactory::createMany(2, $shared);

        $paginator = $this->query->getPaginator(new AllocationQueryParametersDTO(
            urgency: 'not-a-urgency',
        ));

        self::assertCount(2, iterator_to_array($paginator->getResults()));
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
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne();

        return [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ];
    }
}
