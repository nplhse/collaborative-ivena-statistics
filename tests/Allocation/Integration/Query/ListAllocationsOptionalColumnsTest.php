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
final class ListAllocationsOptionalColumnsTest extends KernelTestCase
{
    use Factories;

    private ListAllocationsQuery $query;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(ListAllocationsQuery::class);

        UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatchArea]);
        ImportFactory::createOne();
        SpecialityFactory::createOne(['name' => 'Chirurgie']);
        DepartmentFactory::createOne(['name' => 'Unfallchirurgie']);
        AssignmentFactory::createOne(['name' => 'RD']);
        IndicationRawFactory::createOne();
    }

    public function testOnlyRequestedOptionalColumnsAreAdded(): void
    {
        AllocationFactory::createOne(['occasion' => OccasionFactory::createOne(['name' => 'Verkehrsunfall'])]);
        AllocationFactory::createOne(['occasion' => null]);

        $rows = $this->query->getPaginator(new AllocationQueryParametersDTO(), null, ['speciality', 'occasion'])->getItems();

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('Chirurgie', $row['speciality']);
            self::assertArrayNotHasKey('department', $row);
            self::assertArrayNotHasKey('assignment', $row);
        }
        self::assertEqualsCanonicalizing(['Verkehrsunfall', null], array_column($rows, 'occasion'));
    }

    public function testWithoutOptionalColumnsNothingIsAdded(): void
    {
        AllocationFactory::createOne();

        $row = $this->query->getPaginator(new AllocationQueryParametersDTO())->getItems()[0];

        self::assertArrayNotHasKey('speciality', $row);
        self::assertArrayHasKey('transportType', $row);
        self::assertArrayHasKey('secondaryTransport', $row);
    }

    public function testPageSizeOverridesTheQueryLimit(): void
    {
        AllocationFactory::createMany(4);

        $paginator = $this->query->getPaginator(new AllocationQueryParametersDTO(limit: 50), null, [], 3);

        self::assertCount(3, $paginator->getItems());
        self::assertSame(3, $paginator->getItemsPerPage());
    }
}
