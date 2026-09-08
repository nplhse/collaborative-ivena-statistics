<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
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
final class ListAllocationsSecondaryIndicationFilterTest extends KernelTestCase
{
    use Factories;

    private ListAllocationsQuery $query;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(ListAllocationsQuery::class);
    }

    public function testFiltersBySecondaryIndicationAbsenceAndNormalizedId(): void
    {
        $shared = $this->seedAllocationGraph();

        $target = IndicationNormalizedFactory::createOne([
            'name' => 'Target Secondary',
            'code' => 6101,
        ]);
        $other = IndicationNormalizedFactory::createOne([
            'name' => 'Other Secondary',
            'code' => 6102,
        ]);

        AllocationFactory::createOne(['secondaryIndicationNormalized' => null] + $shared);
        AllocationFactory::createOne(['secondaryIndicationNormalized' => $target] + $shared);
        AllocationFactory::createOne(['secondaryIndicationNormalized' => $other] + $shared);

        $noneResults = iterator_to_array($this->query->getPaginator(new AllocationQueryParametersDTO(
            secondaryIndication: 'none',
        ))->getResults());
        self::assertCount(1, $noneResults);
        self::assertNull($noneResults[0]['secondaryIndicationNormalizedName']);

        $idResults = iterator_to_array($this->query->getPaginator(new AllocationQueryParametersDTO(
            secondaryIndication: (string) $target->getId(),
        ))->getResults());
        self::assertCount(1, $idResults);
        self::assertSame('Target Secondary', $idResults[0]['secondaryIndicationNormalizedName']);

        $ignoredAny = iterator_to_array($this->query->getPaginator(new AllocationQueryParametersDTO(
            secondaryIndication: 'any',
        ))->getResults());
        self::assertCount(3, $ignoredAny);
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
