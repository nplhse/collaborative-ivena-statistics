<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class EncounterAccessGrantControllerTest extends WebTestCase
{
    use EncounterTestFixtures;
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testOwnerGrantsCaseAccessToAHospitalMember(): void
    {
        $client = self::createClient();
        $owner = $this->user();
        $hospital = $this->hospital($owner);
        $member = $this->user(username: 'encounter-member');
        $this->hospitalGrant($hospital, $member);
        $this->user(username: 'outsider');

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/encounter-access/new');
        self::assertResponseIsSuccessful();

        $options = $crawler->filter('select[name="encounter_access_grant[user]"] option')->each(
            static fn ($option): string => trim($option->text()),
        );
        self::assertContains('encounter-member', $options);
        self::assertContains((string) $owner->getUsername(), $options, 'Owners may grant themselves case access.');
        self::assertNotContains('outsider', $options);

        $form = $crawler->filter('form[name="encounter_access_grant"]')->form();
        $form['encounter_access_grant[user]'] = (string) $member->getId();
        $this->selectActions($form, [EncounterGrantAction::Review]);
        $client->submit($form);

        self::assertResponseRedirects('/hospitals/'.$hospital->getId().'/edit/encounter-access');
        $grant = $this->findEncounterGrant($hospital, $member);
        self::assertNotNull($grant);
        self::assertSame([EncounterGrantAction::Review, EncounterGrantAction::ViewCases], $grant->getActions());
    }

    public function testUserWithoutHospitalAccessCannotReceiveAGrant(): void
    {
        $client = self::createClient();
        $owner = $this->user();
        $hospital = $this->hospital($owner);
        $outsider = $this->user();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/encounter-access/new');
        $token = $crawler->filter('input[name="encounter_access_grant[_token]"]')->attr('value');

        $client->request(Request::METHOD_POST, '/hospitals/'.$hospital->getId().'/edit/encounter-access/new', [
            'encounter_access_grant' => [
                'user' => (string) $outsider->getId(),
                'actions' => [EncounterGrantAction::ViewCases->value],
                '_token' => $token,
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->findEncounterGrant($hospital, $outsider));
    }

    public function testOwnerChangesAndRemovesAGrant(): void
    {
        $client = self::createClient();
        $owner = $this->user();
        $hospital = $this->hospital($owner);
        $member = $this->user();
        $this->hospitalGrant($hospital, $member);
        $grant = $this->encounterGrant($hospital, $member, [EncounterGrantAction::Upload]);
        $base = '/hospitals/'.$hospital->getId().'/edit/encounter-access';

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $base.'/'.$grant->getId());
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="encounter_access_grant"]')->form();
        $this->selectActions($form, [EncounterGrantAction::ViewCases]);
        $client->submit($form);
        self::assertResponseRedirects($base);
        self::assertSame([EncounterGrantAction::ViewCases], $this->findEncounterGrant($hospital, $member)?->getActions());

        $crawler = $client->request(Request::METHOD_GET, $base);
        $client->submit($crawler->filter('form[action$="/encounter-access/'.$grant->getId().'/delete"]')->form());
        self::assertResponseRedirects($base);
        self::assertNull($this->findEncounterGrant($hospital, $member));
    }

    public function testAdminManagesGrantsOfAnyHospital(): void
    {
        $client = self::createClient();
        $hospital = $this->hospital($this->user());

        $client->loginUser($this->admin());
        $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/encounter-access');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test="encounter-grants-empty"]');
    }

    public function testHospitalMemberCannotManageGrants(): void
    {
        $client = self::createClient();
        $hospital = $this->hospital($this->user());
        $member = $this->user();
        $this->hospitalGrant($hospital, $member);
        $this->encounterGrant($hospital, $member, EncounterGrantAction::cases());

        $client->loginUser($member);
        $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/encounter-access');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testOwnerWithoutBetaRoleCannotManageGrants(): void
    {
        $client = self::createClient();
        $owner = $this->user(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $hospital = $this->hospital($owner);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/encounter-access');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/access');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/edit/encounter-access', (string) $client->getResponse()->getContent());
    }

    public function testGrantOfAnotherHospitalIsNotFoundByUrl(): void
    {
        $client = self::createClient();
        $owner = $this->user();
        $ownHospital = $this->hospital($owner);
        $foreignHospital = $this->hospital($this->user());
        $foreignMember = $this->user();
        $this->hospitalGrant($foreignHospital, $foreignMember);
        $foreignGrant = $this->encounterGrant($foreignHospital, $foreignMember, [EncounterGrantAction::ViewCases]);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/hospitals/'.$ownHospital->getId().'/edit/encounter-access/'.$foreignGrant->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request(Request::METHOD_GET, '/hospitals/'.$foreignHospital->getId().'/edit/encounter-access/'.$foreignGrant->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testRemovingHospitalAccessOnTheHospitalPageRemovesTheEncounterGrant(): void
    {
        $client = self::createClient();
        $owner = $this->user();
        $hospital = $this->hospital($owner);
        $member = $this->user();
        $hospitalGrant = $this->hospitalGrant($hospital, $member);
        $this->encounterGrant($hospital, $member, [EncounterGrantAction::ViewCases]);

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/access');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '/hospitals/'.$hospital->getId().'/edit/encounter-access',
            (string) $client->getResponse()->getContent(),
            'The hospital edit pages link the encounter grant management.',
        );

        $client->submit($crawler->filter('form[action$="/edit/access/'.$hospitalGrant->getId().'/delete"]')->form());
        self::assertResponseRedirects('/hospitals/'.$hospital->getId().'/edit/access');

        self::assertNull($this->findEncounterGrant($hospital, $member));
    }

    public function testNavigationAndOverviewNeedTheBetaRole(): void
    {
        $client = self::createClient();
        $participant = $this->user(['ROLE_USER', 'ROLE_PARTICIPANT']);

        $client->loginUser($participant);
        $client->request(Request::METHOD_GET, '/explore');
        self::assertSelectorNotExists('[data-test="nav-encounter"]');
        $client->request(Request::METHOD_GET, '/encounter');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->loginUser($this->user());
        $client->request(Request::METHOD_GET, '/encounter');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test="nav-encounter"]');
        self::assertSelectorExists('[data-test="encounter-no-grants"]');
    }

    /**
     * @param list<EncounterGrantAction> $actions
     */
    private function selectActions(Form $form, array $actions): void
    {
        $values = array_map(static fn (EncounterGrantAction $action): string => $action->value, $actions);
        /** @var list<ChoiceFormField> $checkboxes */
        $checkboxes = $form->get('encounter_access_grant[actions]');
        foreach ($checkboxes as $checkbox) {
            $value = $checkbox->availableOptionValues()[0];
            \in_array($value, $values, true) ? $checkbox->tick() : $checkbox->untick();
        }
    }
}
