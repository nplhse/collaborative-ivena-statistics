<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Draft autosave, discard and the explicit save of the page block editor (#967, ADR 036).
 */
#[ResetDatabase]
final class PageTranslationDraftControllerTest extends WebTestCase
{
    use Factories;

    public function testAutosaveStoresIncompleteInputWithoutTouchingThePublicPage(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createPublishedTranslation('draft-public');
        $token = $this->openEditorToken($client, $translation);

        $client->request(Request::METHOD_POST, $this->draftUrl($translation), $this->draftPayload($token, 0, ''));

        self::assertResponseIsSuccessful();
        $response = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(1, $response['revision']);

        $stored = $this->reload($translation);
        self::assertSame([['type' => 'quote', 'enabled' => true, 'data' => ['text' => '']]], $stored->getDraftContent());
        self::assertSame('Saved quote', $stored->getContent()[0]['data']['text'] ?? null);

        $client->request(Request::METHOD_GET, '/draft-public');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Saved quote');
    }

    public function testPageDetailMarksTranslationsWithADraft(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createPublishedTranslation('draft-badge');
        $page = $translation->getPage();
        self::assertInstanceOf(Page::class, $page);

        $client->request(Request::METHOD_GET, sprintf('/admin/page/%d', $page->getId()));
        self::assertSelectorTextNotContains('body', 'Unsaved draft');

        $token = $this->openEditorToken($client, $translation);
        $client->request(Request::METHOD_POST, $this->draftUrl($translation), $this->draftPayload($token, 0, 'Typing'));
        $client->request(Request::METHOD_GET, sprintf('/admin/page/%d', $page->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Unsaved draft');
    }

    public function testEditorOpensADraftWithAnEmptyMediaSelection(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createPublishedTranslation('draft-media');
        $token = $this->openEditorToken($client, $translation);

        $client->request(Request::METHOD_POST, $this->draftUrl($translation), [
            '_token' => $token,
            'revision' => '0',
            'PageTranslation' => ['content' => [
                '0' => ['type' => 'image', 'enabled' => '1', 'position' => '0', 'data' => ['mediaId' => '', 'src' => '/a.png', 'alt' => 'A']],
                '1' => ['type' => 'cta', 'enabled' => '1', 'position' => '1', 'data' => ['mediaId' => '', 'headline' => 'Join', 'buttonLabel' => 'Go', 'buttonUrl' => '/go']],
            ]],
        ]);
        self::assertResponseIsSuccessful();

        $crawler = $this->openEditor($client, $translation);
        self::assertSame('/a.png', $crawler->filter('[name="PageTranslation[content][0][data][src]"]')->attr('value'));
        self::assertSame('Join', $crawler->filter('[name="PageTranslation[content][1][data][headline]"]')->attr('value'));
    }

    public function testAutosaveDoesNotWriteAuditEntries(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createPublishedTranslation('draft-audit');
        $token = $this->openEditorToken($client, $translation);
        $before = $this->countAuditEntries();

        $client->request(Request::METHOD_POST, $this->draftUrl($translation), $this->draftPayload($token, 0, 'Typing'));

        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->countAuditEntries());
    }

    public function testAutosaveBasedOnAnOlderRevisionIsRefused(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createPublishedTranslation('draft-stale');
        $token = $this->openEditorToken($client, $translation);

        $client->request(Request::METHOD_POST, $this->draftUrl($translation), $this->draftPayload($token, 0, 'First'));
        self::assertResponseIsSuccessful();
        $client->request(Request::METHOD_POST, $this->draftUrl($translation), $this->draftPayload($token, 0, 'Late'));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(1, json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['revision']);
        self::assertSame('First', $this->reload($translation)->getDraftContent()[0]['data']['text'] ?? null);
    }

    public function testLateAutosaveCannotBringBackADiscardedDraft(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createPublishedTranslation('draft-discard');
        $token = $this->openEditorToken($client, $translation);

        $client->request(Request::METHOD_POST, $this->draftUrl($translation), $this->draftPayload($token, 0, 'Draft'));
        $client->request(Request::METHOD_POST, $this->draftUrl($translation).'/discard', ['_token' => $token, 'revision' => '1']);
        self::assertResponseIsSuccessful();
        $client->request(Request::METHOD_POST, $this->draftUrl($translation), $this->draftPayload($token, 1, 'Late'));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $stored = $this->reload($translation);
        self::assertFalse($stored->hasDraft());
        self::assertSame(2, $stored->getDraftRevision());
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createPublishedTranslation('draft-csrf');

        $client->request(Request::METHOD_POST, $this->draftUrl($translation), $this->draftPayload('invalid', 0, 'Text'));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertFalse($this->reload($translation)->hasDraft());
    }

    public function testNonAdminCannotSaveDrafts(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['username' => 'draft-user-'.bin2hex(random_bytes(4))]));
        $translation = $this->createPublishedTranslation('draft-user');

        $client->request(Request::METHOD_POST, $this->draftUrl($translation), $this->draftPayload('any', 0, 'Text'));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testEditorOpensTheDraftAndExplicitSaveAppliesIt(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createPublishedTranslation('draft-apply');
        $token = $this->openEditorToken($client, $translation);
        $client->request(Request::METHOD_POST, $this->draftUrl($translation), $this->draftPayload($token, 0, 'From draft'));

        $crawler = $this->openEditor($client, $translation);
        self::assertSame('From draft', $crawler->filter('[name="PageTranslation[content][0][data][text]"]')->text());
        self::assertSame('true', $crawler->filter('[data-page-draft-has-draft-value]')->attr('data-page-draft-has-draft-value'));

        $form = $crawler->selectButton('Save changes')->form();
        $values = $form->getPhpValues();
        $values['pageDraftRevision'] = '1';
        $client->request($form->getMethod(), $form->getUri(), $values);

        self::assertResponseRedirects();
        $stored = $this->reload($translation);
        self::assertSame('From draft', $stored->getContent()[0]['data']['text'] ?? null);
        self::assertFalse($stored->hasDraft());
        self::assertSame(2, $stored->getDraftRevision());
    }

    public function testExplicitSaveBasedOnAnOlderRevisionIsRefused(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createPublishedTranslation('draft-conflict');
        $crawler = $this->openEditor($client, $translation);
        $token = (string) $crawler->filter('[data-page-draft-token-value]')->attr('data-page-draft-token-value');

        // Another tab autosaves after this form was loaded.
        $client->request(Request::METHOD_POST, $this->draftUrl($translation), $this->draftPayload($token, 0, 'Other tab'));

        $form = $crawler->selectButton('Save changes')->form();
        $form['PageTranslation[content][0][data][text]'] = 'This tab';
        $values = $form->getPhpValues();
        $values['pageDraftRevision'] = '0';
        $crawler = $client->request($form->getMethod(), $form->getUri(), $values);

        self::assertResponseIsUnprocessable();
        self::assertStringContainsString('changed, saved or discarded elsewhere', $crawler->filter('body')->text());
        $stored = $this->reload($translation);
        self::assertSame('Saved quote', $stored->getContent()[0]['data']['text'] ?? null);
        self::assertSame('Other tab', $stored->getDraftContent()[0]['data']['text'] ?? null);
    }

    private function createPublishedTranslation(string $slug): PageTranslation
    {
        $page = PageFactory::createOne([
            'title' => 'Draft Test',
            'slug' => $slug,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [['type' => 'quote', 'enabled' => true, 'data' => ['text' => 'Saved quote']]],
        ]);

        $translation = $page->translation('en');
        self::assertInstanceOf(PageTranslation::class, $translation);

        return $translation;
    }

    /**
     * @return array<string, mixed>
     */
    private function draftPayload(string $token, int $revision, string $text): array
    {
        return [
            '_token' => $token,
            'revision' => (string) $revision,
            'PageTranslation' => ['content' => [
                '0' => ['type' => 'quote', 'enabled' => '1', 'position' => '0', 'data' => ['text' => $text]],
            ]],
        ];
    }

    private function draftUrl(PageTranslation $translation): string
    {
        return sprintf('/admin/page-translation/%d/draft', $translation->getId());
    }

    private function openEditor(KernelBrowser $client, PageTranslation $translation): Crawler
    {
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/page-translation/%d/edit', $translation->getId()));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function openEditorToken(KernelBrowser $client, PageTranslation $translation): string
    {
        $token = $this->openEditor($client, $translation)
            ->filter('[data-page-draft-token-value]')
            ->attr('data-page-draft-token-value');
        self::assertNotEmpty($token);

        return (string) $token;
    }

    private function reload(PageTranslation $translation): PageTranslation
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $reloaded = $entityManager->find(PageTranslation::class, $translation->getId());
        self::assertInstanceOf(PageTranslation::class, $reloaded);

        return $reloaded;
    }

    private function countAuditEntries(): int
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager->getRepository(AuditEntry::class)->count(['entityClass' => PageTranslation::class]);
    }

    private function createAdminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create([
            'username' => 'draft-admin-'.bin2hex(random_bytes(4)),
        ]));

        return $client;
    }
}
