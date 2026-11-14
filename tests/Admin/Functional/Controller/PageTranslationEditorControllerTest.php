<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PageTranslationFactory;
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

    public function testEditorRendersDataFieldTemplatesForEveryBlockType(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createTranslation([]);

        $crawler = $this->openEditor($client, $translation);
        $templates = $crawler->filter('[data-page-block-type-templates] template');

        self::assertSame(
            ['richtext', 'image', 'cta', 'quote', 'headline', 'highlight', 'accordion'],
            $templates->each(static fn (Crawler $template): string => (string) $template->attr('data-block-type')),
        );
        self::assertStringContainsString(
            'name="PageTranslation[content][__contentname__][data][text]"',
            $templates->filter('[data-block-type="quote"]')->html(),
        );

        $rules = json_decode((string) $crawler->filter('[data-page-block-editor-rules-value]')->attr('data-page-block-editor-rules-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['html'], $rules['richtext']['transferTo']['highlight'] ?? null);
        self::assertSame(['text'], $rules['quote']['content'] ?? null);
    }

    public function testTypeChangeKeepsTheRichTextBodyInOneSave(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createTranslation([
            ['type' => 'richtext', 'enabled' => true, 'data' => ['html' => '<p>Body</p>']],
        ]);

        $crawler = $this->openEditor($client, $translation);
        $form = $crawler->selectButton('Save changes')->form();
        $form['PageTranslation[content][0][type]'] = 'highlight';
        $client->submit($form);

        self::assertResponseRedirects();
        $block = $this->reloadTranslation($translation)->getContent()[0];
        self::assertSame('highlight', $block['type']);
        self::assertSame('<p>Body</p>', $block['data']['html'] ?? null);
    }

    public function testTypeChangeThatWouldDiscardContentIsRefusedAtTheBlock(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createTranslation([
            ['type' => 'headline', 'enabled' => true, 'data' => ['text' => 'Intro', 'level' => 'h2']],
            ['type' => 'richtext', 'enabled' => true, 'data' => ['html' => '<p>Body</p>']],
        ]);

        // Without the editor script the old fields are submitted together with the new type.
        $crawler = $this->openEditor($client, $translation);
        $form = $crawler->selectButton('Save changes')->form();
        $form['PageTranslation[content][1][type]'] = 'quote';
        $form['PageTranslation[content][1][data][html]'] = '<p>Changed body</p>';
        $crawler = $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame('richtext', $crawler->filter('[name="PageTranslation[content][1][type]"] option[selected]')->attr('value'));
        self::assertSame('<p>Changed body</p>', $crawler->filter('[name="PageTranslation[content][1][data][html]"]')->text());

        $invalidBlocks = $crawler->filter('[data-ea-collection-field] .field-collection-item.is-invalid');
        self::assertCount(1, $invalidBlocks);
        self::assertStringContainsString('The block type was not changed', $invalidBlocks->text());
        self::assertSame('richtext', $this->reloadTranslation($translation)->getContent()[1]['type']);
    }

    public function testBlocksAreStoredInTheOrderOfTheirPositions(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createTranslation([
            ['type' => 'headline', 'enabled' => true, 'data' => ['text' => 'First', 'level' => 'h2']],
            ['type' => 'quote', 'enabled' => true, 'data' => ['text' => 'Second']],
            ['type' => 'quote', 'enabled' => true, 'data' => ['text' => 'Third']],
        ]);

        $crawler = $this->openEditor($client, $translation);
        $form = $crawler->selectButton('Save changes')->form();
        $form['PageTranslation[content][0][position]'] = '2';
        $form['PageTranslation[content][1][position]'] = '0';
        $form['PageTranslation[content][2][position]'] = '1';
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame(
            ['Second', 'Third', 'First'],
            array_map(static fn (array $block): mixed => $block['data']['text'] ?? null, $this->reloadTranslation($translation)->getContent()),
        );
    }

    /**
     * Reproduced failure (#967, H5): an accordion moved to the top got new items under the index of another block.
     * With stable keys the editor keeps the accordion's key and sends the order as positions.
     */
    public function testMovedAccordionKeepsItemsAddedAfterTheMove(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createTranslation([
            ['type' => 'headline', 'enabled' => true, 'data' => ['text' => 'Intro', 'level' => 'h2']],
            ['type' => 'richtext', 'enabled' => true, 'data' => ['html' => '<p>Body</p>']],
            ['type' => 'accordion', 'enabled' => true, 'data' => ['items' => [
                ['title' => 'Question A', 'html' => '<p>Answer A</p>', 'openByDefault' => false],
            ]]],
        ]);

        $crawler = $this->openEditor($client, $translation);
        $form = $crawler->selectButton('Save changes')->form();
        $values = $form->getPhpValues();
        $values['PageTranslation']['content'][2]['position'] = '0';
        $values['PageTranslation']['content'][0]['position'] = '1';
        $values['PageTranslation']['content'][1]['position'] = '2';
        $values['PageTranslation']['content'][2]['data']['items'][1] = ['title' => 'Question B', 'html' => '<p>Answer B</p>'];
        $client->request($form->getMethod(), $form->getUri(), $values);

        self::assertResponseRedirects();
        $content = $this->reloadTranslation($translation)->getContent();
        self::assertSame(['accordion', 'headline', 'richtext'], array_column($content, 'type'));
        self::assertSame(
            ['Question A', 'Question B'],
            array_column($content[0]['data']['items'] ?? [], 'title'),
        );
        self::assertSame('Intro', $content[1]['data']['text'] ?? null);
        self::assertSame('<p>Body</p>', $content[2]['data']['html'] ?? null);
    }

    public function testValidationMessagesFollowTheVisibleOrder(): void
    {
        $client = $this->createAdminClient();
        $translation = $this->createTranslation([
            ['type' => 'quote', 'enabled' => true, 'data' => ['text' => 'Valid']],
            ['type' => 'quote', 'enabled' => true, 'data' => ['text' => 'Will be emptied']],
        ]);

        $crawler = $this->openEditor($client, $translation);
        $form = $crawler->selectButton('Save changes')->form();
        $form['PageTranslation[content][0][position]'] = '1';
        $form['PageTranslation[content][1][position]'] = '0';
        $form['PageTranslation[content][1][data][text]'] = '';
        $crawler = $client->submit($form);

        self::assertResponseIsUnprocessable();
        $invalidBlocks = $crawler->filter('[data-ea-collection-field] .field-collection-item.is-invalid');
        self::assertCount(1, $invalidBlocks);
        self::assertStringContainsString('Block 1:', $invalidBlocks->text());
        self::assertSame('0', $crawler->filter('[name="PageTranslation[content][1][position]"]')->attr('value'));
    }

    public function testEditorLinksToTheOtherLocaleAndSavesTheDraftBeforeLeaving(): void
    {
        $client = $this->createAdminClient();
        $english = $this->createTranslation([
            ['type' => 'quote', 'enabled' => true, 'data' => ['text' => 'English']],
        ]);
        $page = $english->getPage();
        self::assertInstanceOf(Page::class, $page);

        $crawler = $this->openEditor($client, $english);
        $switch = $crawler->filter('a[data-page-draft-save-before-leaving][href*="locale=de"]');
        self::assertCount(1, $switch, 'missing German translation: link to the new form');
        self::assertStringContainsString(sprintf('pageId=%d', $page->getId()), (string) $switch->attr('href'));
        self::assertStringContainsString('/admin/page-translation/new', (string) $switch->attr('href'));
        self::assertCount(0, $crawler->filter('a[href*="locale=en"]'), 'no link to the current locale');

        $german = PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => 'de',
            'title' => 'Deutsch',
            'slug' => 'editor-de-'.bin2hex(random_bytes(3)),
        ]);

        $crawler = $this->openEditor($client, $english);
        self::assertCount(
            1,
            $crawler->filter(sprintf('a[data-page-draft-save-before-leaving][href*="/admin/page-translation/%d/edit"]', $german->getId())),
            'existing German translation: link to its editor',
        );
        self::assertGreaterThan(0, $crawler->filter('a[data-page-draft-save-before-leaving][href*="/admin/page/"]')->count(), 'back to page');
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
