<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Hospitals;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
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
        self::assertSelectorTextContains('table', 'Owned Hospital');
        self::assertSelectorTextNotContains('table', 'Foreign Hospital');
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
