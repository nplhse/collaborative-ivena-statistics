<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Navigation;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Shared\Application\Navigation\FooterNavigationProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class FooterNavigationProviderTest extends KernelTestCase
{
    use Factories;

    private FooterNavigationProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->provider = self::getContainer()->get(FooterNavigationProvider::class);
    }

    public function testGetColumnsOmitsEmptyColumns(): void
    {
        PageFactory::createOne([
            'title' => 'About us',
            'slug' => 'about',
            'path' => '/about',
            'key' => PageKey::About,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        $columns = $this->provider->getColumns();
        $keys = array_map(static fn (\App\Shared\Application\Navigation\DTO\FooterNavigationColumn $column): string => $column->key, $columns);

        self::assertContains('project', $keys);
        self::assertNotContains('help', $keys);
        self::assertNotContains('legal', $keys);
        self::assertContains('more', $keys);
    }

    public function testGetColumnsIncludesPublishedCmsPagesAndStaticRoutes(): void
    {
        PageFactory::createOne([
            'title' => 'About us',
            'slug' => 'about',
            'path' => '/about',
            'key' => PageKey::About,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);
        PageFactory::createOne([
            'title' => 'Features overview',
            'slug' => 'features',
            'path' => '/features',
            'key' => PageKey::Features,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);
        PageFactory::createOne([
            'title' => 'Frequently asked questions',
            'slug' => 'faq',
            'path' => '/faq',
            'key' => PageKey::Faq,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);
        PageFactory::createOne([
            'title' => 'Imprint',
            'slug' => 'imprint',
            'path' => '/imprint',
            'key' => PageKey::Imprint,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);
        PageFactory::createOne([
            'title' => 'Privacy policy',
            'slug' => 'privacy',
            'path' => '/privacy',
            'key' => PageKey::Privacy,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);
        PageFactory::createOne([
            'title' => 'Terms of use',
            'slug' => 'terms',
            'path' => '/terms',
            'key' => PageKey::Terms,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        $columns = $this->provider->getColumns();

        self::assertCount(4, $columns);

        $projectColumn = $columns[0];
        self::assertSame('project', $projectColumn->key);
        self::assertCount(3, $projectColumn->links);
        self::assertSame('About us', $projectColumn->links[0]->label);
        self::assertStringContainsString('/about', $projectColumn->links[0]->url);
        self::assertStringContainsString('/blog', $projectColumn->links[2]->url);

        $helpColumn = $columns[1];
        self::assertSame('help', $helpColumn->key);
        self::assertCount(1, $helpColumn->links);
        self::assertSame('Frequently asked questions', $helpColumn->links[0]->label);

        $legalColumn = $columns[2];
        self::assertSame('legal', $legalColumn->key);
        self::assertCount(3, $legalColumn->links);
        self::assertSame('Terms of use', $legalColumn->links[2]->label);
        self::assertStringContainsString('/terms', $legalColumn->links[2]->url);

        $moreColumn = $columns[3];
        self::assertSame('more', $moreColumn->key);
        self::assertCount(2, $moreColumn->links);
        self::assertStringContainsString('/sitemap', $moreColumn->links[0]->url);
        self::assertStringContainsString('/cookies/preferences', $moreColumn->links[1]->url);
    }

    public function testGetColumnsSkipsDraftPages(): void
    {
        PageFactory::createOne([
            'title' => 'Draft about',
            'slug' => 'about-draft',
            'path' => '/about-draft',
            'key' => PageKey::About,
            'status' => Page::STATUS_DRAFT,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        $keys = array_map(
            static fn (\App\Shared\Application\Navigation\DTO\FooterNavigationColumn $column): string => $column->key,
            $this->provider->getColumns(),
        );

        self::assertContains('project', $keys);
        self::assertNotContains('help', $keys);

        $projectColumn = array_values(array_filter(
            $this->provider->getColumns(),
            static fn (\App\Shared\Application\Navigation\DTO\FooterNavigationColumn $column): bool => 'project' === $column->key,
        ))[0];
        self::assertCount(1, $projectColumn->links);
        self::assertStringContainsString('/blog', $projectColumn->links[0]->url);

        $moreColumn = array_values(array_filter(
            $this->provider->getColumns(),
            static fn (\App\Shared\Application\Navigation\DTO\FooterNavigationColumn $column): bool => 'more' === $column->key,
        ))[0];
        self::assertCount(2, $moreColumn->links);
        self::assertStringContainsString('/sitemap', $moreColumn->links[0]->url);
    }
}
