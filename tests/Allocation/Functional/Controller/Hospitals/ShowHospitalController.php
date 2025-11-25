<?php

namespace App\Tests\Allocation\Functional\Controller\Hospitals;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AddressFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class ShowHospitalController extends WebTestCase
{
    use Factories;

    public function testShowDisplaysHospitalDetails(): void
    {
        // Arrange
        $client = static::createClient();

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

        $hospital = HospitalFactory::createOne([
            'name' => 'St. Test Hospital',
            'beds' => 321,
            'address' => $address,
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
            'location' => HospitalLocation::cases()[0],
            'size' => HospitalSize::cases()[0],
            'tier' => HospitalTier::cases()[0],
            'createdAt' => new \DateTimeImmutable('2025-01-02 03:04:05'),
        ]);

        // Act
        $crawler = $client->request('GET', '/explore/hospital/'.$hospital->getId());

        // Assert
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('#hospital-name', 'St. Test Hospital');
        self::assertSelectorTextContains('#hospital-created-by', 'area-user');
        self::assertSelectorTextContains('#hospital-owned-by', 'owner-user');
        self::assertSelectorTextContains('#hospital-created-at', '01.02.2025');
        self::assertSelectorTextContains('#hospital-address', 'Teststadt');
        self::assertSelectorTextContains('#hospital-address', 'Hessen');
        self::assertSelectorTextContains('#hospital-size', HospitalSize::cases()[0]->value);
        self::assertSelectorTextContains('#hospital-beds', '321');
        self::assertSelectorTextContains('#hospital-location', HospitalLocation::cases()[0]->value);
        self::assertSelectorTextContains('#hospital-tier', HospitalTier::cases()[0]->value);
    }

    public function testShow404ForUnknownHospital(): void
    {
        $client = static::createClient();
        $client->request('GET', '/explore/hospital/999999');
        self::assertResponseStatusCodeSame(404);
    }
}
