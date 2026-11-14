<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Factory\PageFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Block editor behaviour of the page translation form (#967).
 */
#[ResetDatabase]
final class PageTranslationEditorControllerTest extends WebTestCase
{
    use Factories;

    public function testInvalidBlockIsReportedAtTheBlockAndKeepsInput(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createTranslation([
            ['type' => 'headline', 'enabled' => true, 'data' => ['text' => 'Intro', 'level' => 'h2']],
            ['type' => 'image', 'enabled' => true, 'data' => ['src' => 'https://example.org/a.png', 'alt' => 'Old alt']],
        ]);

        $crawler = $this->openEditor($client, $translation);
        $form = $crawler->selectButton('Save changes')->form();
        $form['PageTranslation[content][1][data][src]'] = '';
        $form['PageTranslation[content][1][data][alt]'] = 'New alt';
        $crawler = $client->submit($form);

        self::assertResponseIsUnprocessable();

        $invalidBlocks = $crawler->filter('[data-ea-collection-field] .field-collection-item.is-invalid');
        self::assertCount(1, $invalidBlocks);
        self::assertStringContainsString('Block 2:', $invalidBlocks->text());
        self::assertSame('New alt', $crawler->filter('[name="PageTranslation[content][1][data][alt]"]')->attr('value'));

        $stored = $this->reloadTranslation($translation);
        self::assertSame('https://example.org/a.png', $stored->getContent()[1]['data']['src'] ?? null);
        self::assertSame('Old alt', $stored->getContent()[1]['data']['alt'] ?? null);
    }

    public function testValidBlocksAreSaved(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createTranslation([
            ['type' => 'headline', 'enabled' => true, 'data' => ['text' => 'Intro', 'level' => 'h2']],
        ]);

        $crawler = $this->openEditor($client, $translation);
        $form = $crawler->selectButton('Save changes')->form();
        $form['PageTranslation[content][0][data][text]'] = 'Renamed intro';
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame('Renamed intro', $this->reloadTranslation($translation)->getContent()[0]['data']['text'] ?? null);
    }

    public function testNewBlockPrototypeIsEnabledRichText(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createTranslation([]);

        $crawler = $this->openEditor($client, $translation);
        $prototype = new Crawler((string) $crawler
            ->filter('[data-ea-collection-field][data-form-type-name-placeholder="__contentname__"]')
            ->attr('data-prototype'));

        $enabled = $prototype->filter('input[type="checkbox"][name$="[enabled]"]');
        self::assertCount(1, $enabled);
        self::assertSame('checked', $enabled->attr('checked'));
        self::assertSame('richtext', $prototype->filter('select[name$="[type]"] option[selected]')->attr('value'));
    }

    public function testSaveAndContinueStaysInTheEditor(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createTranslation([
            ['type' => 'headline', 'enabled' => true, 'data' => ['text' => 'Intro', 'level' => 'h2']],
        ]);

        $crawler = $this->openEditor($client, $translation);
        $form = $crawler->filter('button[name="ea[newForm][btn]"][value="saveAndContinue"]')->form();
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertStringContainsString(
            sprintf('/admin/page-translation/%d/edit', $translation->getId()),
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    public function testSaveReturnsToThePageDetail(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createTranslation([
            ['type' => 'headline', 'enabled' => true, 'data' => ['text' => 'Intro', 'level' => 'h2']],
        ]);

        $crawler = $this->openEditor($client, $translation);
        $client->submit($crawler->selectButton('Save changes')->form());

        self::assertResponseRedirects();
        $page = $translation->getPage();
        self::assertInstanceOf(Page::class, $page);
        self::assertStringContainsString(
            sprintf('/admin/page/%d', $page->getId()),
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    public function testBlockFieldLabelsAreTranslated(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createTranslation([
            ['type' => 'richtext', 'enabled' => true, 'data' => ['html' => '<p>Text</p>']],
            ['type' => 'quote', 'enabled' => true, 'data' => ['text' => 'Quote']],
        ]);

        $crawler = $this->openEditor($client, $translation);

        self::assertSame('HTML', trim($crawler->filter('label[for="PageTranslation_content_0_data_html"]')->text()));
        self::assertSame('Quote text', trim($crawler->filter('label[for="PageTranslation_content_1_data_text"]')->text()));
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function createTranslation(array $content): PageTranslation
    {
        $page = PageFactory::createOne([
            'title' => 'Editor Test',
            'slug' => 'editor-test-'.bin2hex(random_bytes(3)),
            'status' => PageTranslation::STATUS_DRAFT,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => $content,
        ]);

        $translation = $page->translation('en');
        self::assertInstanceOf(PageTranslation::class, $translation);

        return $translation;
    }

    private function openEditor(KernelBrowser $client, PageTranslation $translation): Crawler
    {
        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf('/admin/page-translation/%d/edit', $translation->getId()),
        );
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function reloadTranslation(PageTranslation $translation): PageTranslation
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $reloaded = $entityManager->find(PageTranslation::class, $translation->getId());
        self::assertInstanceOf(PageTranslation::class, $reloaded);

        return $reloaded;
    }

    private function createAdminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create([
            'username' => 'page-editor-admin-'.bin2hex(random_bytes(4)),
        ]));

        return $client;
    }
}
