<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Hospitals;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AddressFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ShowHospitalControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use ResetDatabase;

    public function testShowDisplaysHospitalDetails(): void
    {
        $client = $this->createClientAsParticipant();

        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $stateName = 'Hessen';
        $state = StateFactory::createOne(['name' => $stateName]);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area', 'state' => $state]);
        $address = AddressFactory::new([
            'street' => 'Fake Street 123',
            'postalCode' => '12345',
            'city' => 'Teststadt',
            'state' => $stateName,
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

        $client->request(Request::METHOD_GET, '/explore/hospital/'.$hospital->getId());

        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('#hospital-name', 'St. Test Hospital');
        self::assertSelectorTextContains('#hospital-created-by', 'area-user');
        self::assertSelectorTextContains('#hospital-owned-by', 'owner-user');
        self::assertSelectorTextContains('#hospital-created-at', '02.01.2025');
        self::assertSelectorTextContains('#hospital-address', 'Teststadt');
        self::assertSelectorTextContains('#hospital-address', $stateName);
        self::assertSelectorTextContains('#hospital-size', HospitalSize::cases()[0]->value);
        self::assertSelectorTextContains('#hospital-beds', '321');
        self::assertSelectorTextContains('#hospital-location', HospitalLocation::cases()[0]->value);
        self::assertSelectorTextContains('#hospital-tier', HospitalTier::cases()[0]->value);
        self::assertSelectorNotExists('a.btn-primary[href="/hospitals/'.$hospital->getId().'/edit"]');
    }

    public function testOwnerSeesEditButtonOnOwnHospitalShowPage(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'owner-user']);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner, 'name' => 'Owned Clinic']);

        $client->loginUser($owner->_real());
        $client->request(Request::METHOD_GET, '/explore/hospital/'.$hospital->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.btn-primary[href="/hospitals/'.$hospital->getId().'/edit"]');
    }

    public function testAdminSeesEditButtonOnForeignHospitalShowPage(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner, 'name' => 'Foreign Clinic']);

        $client->loginUser($admin->_real());
        $client->request(Request::METHOD_GET, '/explore/hospital/'.$hospital->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.btn-primary[href="/hospitals/'.$hospital->getId().'/edit"]');
    }

    public function testShowRejectsPostMethod(): void
    {
        $client = $this->createClientAsParticipant();
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Post guard hospital',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $client->request(Request::METHOD_POST, '/explore/hospital/'.$hospital->getId());

        self::assertResponseStatusCodeSame(405);
    }

    public function testShow404ForUnknownHospital(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_GET, '/explore/hospital/999999');
        self::assertResponseStatusCodeSame(404);
    }
}
