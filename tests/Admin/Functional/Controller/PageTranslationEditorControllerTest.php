<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Factory\PageFactory;
use App\User\Domain\Factory\UserFactory;
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

    private function createAdminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create([
            'username' => 'page-editor-admin-'.bin2hex(random_bytes(4)),
        ]));

        return $client;
    }
}
