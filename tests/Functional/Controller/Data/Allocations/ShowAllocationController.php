<?php

namespace App\Tests\Functional\Controller\Data\Allocations;

use App\Enum\HospitalLocation;
use App\Enum\HospitalSize;
use App\Enum\HospitalTier;
use App\Factory\AddressFactory;
use App\Factory\AllocationFactory;
use App\Factory\DepartmentFactory;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\SpecialityFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class ShowAllocationController extends WebTestCase
{
    use Factories;

    public function testShowDisplaysHospitalDetails(): void
    {
        // Arrange
        $client = self::createClient();

        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        $address = AddressFactory::new([
            'street' => 'Fake Street 123',
            'postalCode' => '12345',
            'city' => 'Teststadt',
            'country' => 'DE',
        ])->create();

        $department = DepartmentFactory::createOne(['name' => 'Test Department']);
        $speciality = SpecialityFactory::createOne(['name' => 'Test Speciality']);

        $hospital = HospitalFactory::createOne([
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
        $client->request('GET', '/data/allocation/'.$allocation->getId());

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
        $client = self::createClient();
        $client->request('GET', '/data/hospital/999999');
        self::assertResponseStatusCodeSame(404);
    }
}
