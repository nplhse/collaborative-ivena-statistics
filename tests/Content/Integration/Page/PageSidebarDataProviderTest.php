<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PageSidebarDataProvider;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PostFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PageSidebarDataProviderTest extends KernelTestCase
{
    use Factories;

    public function testGetDataIncludesLatestPublishedPosts(): void
    {
        self::bootKernel();

        PostFactory::createOne([
            'title' => 'Sidebar Post',
            'slug' => 'sidebar-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
        ]);

        $data = self::getContainer()->get(PageSidebarDataProvider::class)->getData();

        self::assertSame(['Sidebar Post'], array_map(static fn ($post): ?string => $post->getTitle(), $data['latest_posts']));
    }
}
