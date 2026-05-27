<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Allocations;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AddressFactory;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;

final class ShowAllocationController extends WebTestCase
{
    use InteractsWithAuthenticatedUser;

    use Factories;

    public function testShowDisplaysHospitalDetails(): void
    {
        // Arrange
        $client = $this->createClientAsRoleUser();

        UserFactory::createOne(['username' => 'owner-user']);
        UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        $address = AddressFactory::new([
            'street' => 'Fake Street 123',
            'postalCode' => '12345',
            'city' => 'Teststadt',
            'country' => 'DE',
        ])->create();

        DepartmentFactory::createOne(['name' => 'Test Department']);
        SpecialityFactory::createOne(['name' => 'Test Speciality']);

        IndicationRawFactory::createOne(['name' => 'Test Indication']);
        IndicationNormalizedFactory::createOne(['name' => 'Test Indication']);

        HospitalFactory::createOne([
            'name' => 'St. Test Hospital',
            'beds' => 321,
            'address' => $address,
            'state' => $state,
            'dispatchArea' => $dispatch,
            'location' => HospitalLocation::cases()[0],
            'size' => HospitalSize::cases()[0],
            'tier' => HospitalTier::cases()[0],
        ]);

        $allocation = AllocationFactory::createOne([
            'age' => '99',
            'gender' => 'M',
            'createdAt' => new \DateTimeImmutable('2025-01-02 03:04:05'),
            'arrivalAt' => new \DateTimeImmutable('2025-02-02 03:15:05'),
        ]);

        // Act
        $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getId());

        // Assert
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('#allocation-id', '#'.$allocation->getId());
        self::assertSelectorTextContains('#allocation-created-by', '01.02.2025');
        self::assertSelectorTextContains('#allocation-arrival-at', '01.02.2025');
        self::assertSelectorTextContains('#allocation-age', '99');
        self::assertSelectorTextContains('#allocation-gender', 'Male');
        self::assertSelectorTextContains('#hospital-address', 'Teststadt');
        self::assertSelectorTextContains('#hospital-address', 'Hessen');
        self::assertSelectorTextContains('#hospital-size', HospitalSize::cases()[0]->value);
        self::assertSelectorTextContains('#hospital-beds', '321');
        self::assertSelectorTextContains('#hospital-location', HospitalLocation::cases()[0]->value);
        self::assertSelectorTextContains('#hospital-tier', HospitalTier::cases()[0]->value);
        self::assertSelectorTextContains('#department-line', 'Test Department');
        self::assertSelectorTextContains('#department-line', 'Test Speciality');
    }

    public function testShow404ForUnknownHospital(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/explore/hospital/999999');
        self::assertResponseStatusCodeSame(404);
    }
}
