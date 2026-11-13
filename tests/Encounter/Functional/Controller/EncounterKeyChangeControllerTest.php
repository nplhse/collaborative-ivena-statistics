<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Key change page (ADR 027, #1050): hospital owner or administrator only, confirmation by the hospital
 * name. The flow itself is covered by EncounterKeyChangeTest.
 */
#[ResetDatabase]
final class EncounterKeyChangeControllerTest extends WebTestCase
{
    use EncounterTestFixtures;
    use Factories;

    private KernelBrowser $client;

    private User $owner;

    private Hospital $hospital;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->owner = $this->user();
        $this->hospital = $this->hospital($this->owner);
    }

    public function testOwnerChangesTheKeyAfterTypingTheHospitalName(): void
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request(Request::METHOD_GET, $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="encounter-key-change-purpose"]', 'without learning who the patient is');
        self::assertSelectorTextContains('[data-test="encounter-key-change-when"]', 'lost');
        self::assertSelectorTextContains('[data-test="encounter-key-change-option-keep"]', 'counted twice');
        self::assertSelectorTextContains('[data-test="encounter-key-change-option-delete"]', 'There are no encounters yet.');
        self::assertSelectorExists('[data-test="encounter-key-change-safety"] input[name="encounter_key_change[confirmName]"]');
        self::assertSelectorExists('[data-test="encounter-key-change-safety"] button[data-bs-target="#encounter-key-change-modal"][data-encounter-key-change-target="submit"]');
        self::assertSelectorExists('[data-test="encounter-key-change-modal"] button[type="submit"][form="encounter-key-change-form"]');
        $controller = $crawler->filter('[data-controller="encounter-key-change"]');
        self::assertSame((string) $this->hospital->getName(), $controller->attr('data-encounter-key-change-hospital-name-value'));
        self::assertStringContainsString('only upload new periods', (string) $controller->attr('data-encounter-key-change-keep-message-value'));
        self::assertSelectorExists('a[href="'.$this->url().'"]');

        $form = $crawler->filter('form[name="encounter_key_change"]')->form();
        $form['encounter_key_change[option]'] = 'keep';
        $form['encounter_key_change[confirmName]'] = (string) $this->hospital->getName();
        $this->client->submit($form);

        self::assertResponseRedirects($this->url());
        self::assertSame(['key_generation' => 2, 'key_change_in_progress' => false], $this->keyState());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The key has been changed. Your existing encounters have been kept.');
    }

    public function testHospitalNameInTheHintIsEmphasisedAndEscaped(): void
    {
        $hospital = $this->entityManager()->find(Hospital::class, (int) $this->hospital->getId());
        self::assertInstanceOf(Hospital::class, $hospital);
        $hospital->setName('Klinik <A & B>');
        $this->entityManager()->flush();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request(Request::METHOD_GET, $this->url());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '<strong><em>Klinik &lt;A &amp; B&gt;</em></strong>',
            $crawler->filter('#encounter_key_change_confirmName_help')->html(),
        );
    }

    public function testWrongHospitalNameChangesNothing(): void
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request(Request::METHOD_GET, $this->url());

        $form = $crawler->filter('form[name="encounter_key_change"]')->form();
        $form['encounter_key_change[option]'] = 'delete';
        $form['encounter_key_change[confirmName]'] = 'Another hospital';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('form[name="encounter_key_change"]', 'The name does not match this hospital.');
        self::assertNull($this->keyState());
    }

    public function testAdministratorCanChangeTheKey(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request(Request::METHOD_GET, $this->url());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="encounter_key_change"]')->form();
        $form['encounter_key_change[option]'] = 'delete';
        $form['encounter_key_change[confirmName]'] = (string) $this->hospital->getName();
        $this->client->submit($form);

        self::assertResponseRedirects($this->url());
        self::assertSame(['key_generation' => 2, 'key_change_in_progress' => false], $this->keyState());
    }

    public function testUserWithEveryEncounterGrantCannotChangeTheKey(): void
    {
        $uploader = $this->user();
        $this->hospitalGrant($this->hospital, $uploader);
        $this->encounterGrant($this->hospital, $uploader, [EncounterGrantAction::Upload, EncounterGrantAction::Review]);

        $this->client->loginUser($uploader);
        $this->client->request(Request::METHOD_GET, $this->url());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request(Request::METHOD_POST, $this->url(), [
            'encounter_key_change' => ['option' => 'delete', 'confirmName' => (string) $this->hospital->getName()],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNull($this->keyState());
    }

    public function testOwnerWithoutBetaRoleCannotChangeTheKey(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $hospital = $this->hospital($owner);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/encounter-key');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testPageShowsTheChangeInProgressInsteadOfTheForm(): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            "INSERT INTO encounter_key_state (hospital_id, key_generation, key_change_in_progress, created_at) VALUES (?, 2, true, '2026-11-01 10:00:00')",
            [$this->hospital->getId()],
        );

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test="encounter-key-change-in-progress"]');
        self::assertSelectorNotExists('form[name="encounter_key_change"]');
    }

    private function url(): string
    {
        return '/hospitals/'.$this->hospital->getId().'/edit/encounter-key';
    }

    /**
     * @return array{key_generation: int, key_change_in_progress: bool}|null
     */
    private function keyState(): ?array
    {
        $row = $this->entityManager()->getConnection()->fetchAssociative(
            'SELECT key_generation, key_change_in_progress FROM encounter_key_state WHERE hospital_id = ?',
            [$this->hospital->getId()],
        );

        return false === $row ? null : ['key_generation' => (int) $row['key_generation'], 'key_change_in_progress' => (bool) $row['key_change_in_progress']];
    }
}
