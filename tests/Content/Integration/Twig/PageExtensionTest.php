<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Twig;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PageExtensionTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private Environment $twig;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get(Environment::class);
    }

    public function testPageByKeyReturnsPageForPublishedKey(): void
    {
        PageFactory::createOne([
            'slug' => 'faq-page',
            'path' => '/help/faq',
            'key' => PageKey::Faq,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        $html = $this->twig->createTemplate('{{ page_by_key("faq") ? "found" : "missing" }}')->render();

        self::assertSame('found', $html);
    }

    public function testPageByKeyReturnsNullWhenPageMissing(): void
    {
        $html = $this->twig->createTemplate('{{ page_by_key("faq") is null ? "null" : "present" }}')->render();

        self::assertSame('null', $html);
    }

    public function testPageByKeyReturnsNullForInvalidKeyString(): void
    {
        $html = $this->twig->createTemplate('{{ page_by_key("not-a-key") is null ? "null" : "present" }}')->render();

        self::assertSame('null', $html);
    }

    public function testPageUrlByKeyReturnsUrlFromPath(): void
    {
        $parent = PageFactory::createOne([
            'slug' => 'legal',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ])->_real();

        PageFactory::createOne([
            'parent' => $parent,
            'slug' => 'privacy-notice',
            'key' => PageKey::Privacy,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        $html = $this->twig->createTemplate('{{ page_url_by_key("privacy") }}')->render();

        self::assertStringContainsString('/legal/privacy-notice', $html);
        self::assertStringNotContainsString('/privacy-slug', $html);
    }

    public function testPageNavHeaderItemsOnlyListsAvailablePages(): void
    {
        PageFactory::createOne([
            'slug' => 'about-nav',
            'path' => '/about-nav',
            'key' => PageKey::About,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'title' => 'About Nav',
        ]);

        $html = $this->twig->createTemplate('{% for item in page_nav_header_items() %}{{ item.label }};{% endfor %}')->render();

        self::assertSame('About Nav;', $html);
    }
}
