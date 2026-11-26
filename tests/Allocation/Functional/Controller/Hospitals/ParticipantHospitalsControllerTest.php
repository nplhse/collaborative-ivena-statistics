<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Hospitals;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Tests\Support\Translation\AssertsNoMissingTranslations;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ParticipantHospitalsControllerTest extends WebTestCase
{
    use AssertsNoMissingTranslations;
    use Factories;

    public function testHospitalsIndexRedirectsWhenNotAuthenticated(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseRedirects('/login');
    }

    public function testHospitalsIndexIsForbiddenWithoutParticipantRole(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseStatusCodeSame(403);
    }

    public function testHospitalsIndexShowsOnlyOwnedHospitals(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne([
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
            'username' => 'participant-owner',
        ]);
        $otherOwner = UserFactory::createOne([
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
            'username' => 'participant-other',
        ]);
        $createdBy = UserFactory::createOne(['username' => 'creator-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'DA Test']);

        HospitalFactory::createOne([
            'name' => 'Owned Hospital',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);
        HospitalFactory::createOne([
            'name' => 'Foreign Hospital',
            'owner' => $otherOwner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'My Hospitals');
        self::assertSelectorTextContains('[data-testid="my-hospitals-cards"]', 'Owned Hospital');
        self::assertSelectorTextNotContains('[data-testid="my-hospitals-cards"]', 'Foreign Hospital');
    }

    public function testHospitalsIndexShowsGrantedHospitalsWithoutEditActions(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();

        $granted = HospitalFactory::createOne([
            'name' => 'Granted Hospital',
            'owner' => $owner,
            'createdBy' => $owner,
        ]);
        $withoutView = HospitalFactory::createOne([
            'name' => 'Grant Without View',
            'owner' => $owner,
            'createdBy' => $owner,
        ]);
        HospitalAccessGrantFactory::createOne([
            'hospital' => $granted,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $owner,
        ]);
        HospitalAccessGrantFactory::createOne([
            'hospital' => $withoutView,
            'user' => $grantee,
            'permissions' => 0,
            'createdBy' => $owner,
        ]);

        $client->loginUser($grantee);
        $client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="my-hospitals-cards"]', 'Granted Hospital');
        self::assertSelectorTextNotContains('[data-testid="my-hospitals-cards"]', 'Grant Without View');
        self::assertSelectorNotExists('a[href="/hospitals/'.$granted->getId().'/edit"]');
        self::assertSelectorNotExists('a[href="/hospitals/'.$granted->getId().'/edit/access"]');
    }

    public function testHospitalsIndexDoesNotListForeignHospitalsForAdmin(): void
    {
        $client = self::createClient();

        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();

        HospitalFactory::createOne(['name' => 'Admin Hospital', 'owner' => $admin, 'createdBy' => $admin]);
        HospitalFactory::createOne(['name' => 'Foreign Hospital', 'owner' => $owner, 'createdBy' => $owner]);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="my-hospitals-cards"]', 'Admin Hospital');
        self::assertSelectorTextNotContains('[data-testid="my-hospitals-cards"]', 'Foreign Hospital');
    }

    public function testHospitalsIndexShowsDataSummaryPerPermission(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $viewer = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne();

        $hospital = HospitalFactory::createOne(['name' => 'Data Hospital', 'owner' => $owner, 'createdBy' => $owner]);
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $owner,
            'status' => ImportStatus::COMPLETED,
            'type' => ImportType::ALLOCATION,
            'createdAt' => new \DateTimeImmutable('2026-03-10 10:00:00'),
        ]);
        foreach (['2026-02-01 08:00:00', '2026-02-14 12:00:00', '2026-02-28 20:00:00'] as $createdAt) {
            AllocationFactory::createOne([
                'hospital' => $hospital,
                'import' => $import,
                'createdAt' => new \DateTimeImmutable($createdAt),
                'arrivalAt' => new \DateTimeImmutable($createdAt)->modify('+20 minutes'),
            ]);
        }
        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $viewer,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $owner,
        ]);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport((int) $import->getId());

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-allocations"]', '3');
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-period"]', 'Feb 1, 2026');
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-period"]', 'Feb 28, 2026');
        self::assertSelectorTextContains('[data-testid="my-hospitals-card-last-upload"]', 'Mar 10, 2026');
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-success-rate"]', '100 % successful');

        $client->loginUser($viewer);
        $client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-allocations"]', '3');
        self::assertSelectorNotExists('[data-testid="my-hospitals-summary-imports"]', 'Imports need the IMPORT permission.');
        self::assertSelectorNotExists('[data-testid="my-hospitals-card-last-upload"]');
    }

    public function testHospitalsIndexShowsNoSummaryWithoutHospitals(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        $client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="my-hospitals-summary"]');
    }

    public function testEditGeneralPageRendersGermanChoiceLabels(): void
    {
        $client = self::createClient();
        $client->enableProfiler();

        $owner = UserFactory::createOne([
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
            'locale' => 'de',
        ]);
        $createdBy = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();

        $hospital = HospitalFactory::createOne([
            'name' => 'Krankenhaus Choices',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Städtischer Standort');
        self::assertSelectorTextContains('body', 'Basisversorgung');
        self::assertSelectorTextContains('body', 'Klein');

        $this->assertNoMissingTranslations($client->getProfile());
    }

    public function testEditIsForbiddenForNonOwnerParticipant(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $otherParticipant = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $createdBy = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();

        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $client->loginUser($otherParticipant);
        $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit');

        self::assertResponseStatusCodeSame(403);

        $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/address');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanEditForeignHospital(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $createdBy = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();

        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit');
        self::assertResponseIsSuccessful();

        $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/address');
        self::assertResponseIsSuccessful();
    }

    public function testOwnerParticipantCanEditHospitalGeneralData(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $createdBy = UserFactory::createOne();
        $state = StateFactory::createOne(['name' => 'Old State']);
        $otherState = StateFactory::createOne(['name' => 'New State']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Old DA']);
        $otherDispatch = DispatchAreaFactory::createOne(['name' => 'New DA']);

        $hospital = HospitalFactory::createOne([
            'name' => 'Hospital Before Edit',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Manage access', (string) $client->getResponse()->getContent());

        $form = $crawler->selectButton('Save changes')->form([
            'hospital_participant_edit[name]' => 'Hospital After Edit',
            'hospital_participant_edit[dispatchArea]' => (string) $otherDispatch->getId(),
            'hospital_participant_edit[state]' => (string) $otherState->getId(),
            'hospital_participant_edit[location]' => 'Urban',
            'hospital_participant_edit[tier]' => 'Basic',
            'hospital_participant_edit[size]' => 'Small',
            'hospital_participant_edit[beds]' => '123',
            'hospital_participant_edit[isParticipating]' => '1',
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/explore/hospital/'.$hospital->getPublicIdString());

        /** @var Hospital|null $updatedHospital */
        $updatedHospital = self::getContainer()->get('doctrine')->getRepository(Hospital::class)->find($hospital->getId());
        self::assertNotNull($updatedHospital);
        self::assertSame('Hospital After Edit', $updatedHospital->getName());
        self::assertSame(123, $updatedHospital->getBeds());
    }

    public function testAddressEditPageRendersGermanFormLabels(): void
    {
        $client = self::createClient();
        $client->enableProfiler();

        $owner = UserFactory::createOne([
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
            'locale' => 'de',
        ]);
        $createdBy = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();

        $hospital = HospitalFactory::createOne([
            'name' => 'Krankenhaus Adresse',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/address');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('label[for*="street"]', 'Straße');
        self::assertSelectorTextContains('label[for*="postalCode"]', 'Postleitzahl');
        self::assertSelectorTextContains('label[for*="city"]', 'Stadt');

        $this->assertNoMissingTranslations($client->getProfile());
    }

    public function testOwnerParticipantCanEditHospitalAddressData(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $createdBy = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();

        $hospital = HospitalFactory::createOne([
            'name' => 'Hospital Address Edit',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/address');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form([
            'hospital_participant_address_edit[address][street]' => 'New Street 1',
            'hospital_participant_address_edit[address][postalCode]' => '12345',
            'hospital_participant_address_edit[address][city]' => 'New City',
            'hospital_participant_address_edit[address][state]' => 'HS',
            'hospital_participant_address_edit[address][country]' => 'DE',
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/explore/hospital/'.$hospital->getPublicIdString());

        /** @var Hospital|null $updatedHospital */
        $updatedHospital = self::getContainer()->get('doctrine')->getRepository(Hospital::class)->find($hospital->getId());
        self::assertNotNull($updatedHospital);
        self::assertSame('New City', $updatedHospital->getAddress()->getCity());
        self::assertSame('New Street 1', $updatedHospital->getAddress()->getStreet());
    }
}
