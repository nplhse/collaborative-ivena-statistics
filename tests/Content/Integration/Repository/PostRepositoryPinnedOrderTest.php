<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Repository;

use App\Content\Domain\Entity\Post;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PostCategoryFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Content\Infrastructure\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Pinned posts stand first on the blog start page and in the dashboard's blog card (#1486); the sidebars, RSS, the
 * sitemap and category pages keep the date order. Now is 2026-11-22 12:00.
 */
#[ResetDatabase]
final class PostRepositoryPinnedOrderTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::mockTime('2026-11-22 12:00:00');
        self::bootKernel();

        $category = PostCategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);
        $this->post('Older pinned', '2026-11-10 09:00:00', true, $category);
        $this->post('Middle', '2026-11-20 09:00:00', false, $category);
        $this->post('Newest', '2026-11-21 09:00:00', false, $category);
        $this->post('Pinned draft', '2026-11-15 09:00:00', true, $category, PostStatus::DRAFT);
        $this->post('Pinned future', '2026-11-30 09:00:00', true, $category);
    }

    public function testBlogStartPageShowsPinnedPostsFirst(): void
    {
        /** @var list<Post> $posts */
        $posts = $this->repository()->publishedQuery()->getQuery()->getResult();

        self::assertSame(['Older pinned', 'Newest', 'Middle'], $this->titles($posts));
    }

    public function testDashboardCardListsPinnedPostsFirst(): void
    {
        self::assertSame(['Older pinned', 'Newest', 'Middle'], $this->titles($this->repository()->findPublishedForIndex(5, pinnedFirst: true)));
        self::assertSame(['Older pinned', 'Newest'], $this->titles($this->repository()->findPublishedForIndex(2, pinnedFirst: true)));
    }

    public function testDateOrderStaysForSidebarsFeedsAndCategories(): void
    {
        self::assertSame(['Newest', 'Middle', 'Older pinned'], $this->titles($this->repository()->findPublishedForIndex()));

        /** @var list<Post> $posts */
        $posts = $this->repository()->publishedByCategoryQuery('news')->getQuery()->getResult();
        self::assertSame(['Newest', 'Middle', 'Older pinned'], $this->titles($posts));
    }

    private function post(string $title, string $publishedAt, bool $pinned, object $category, PostStatus $status = PostStatus::PUBLISHED): void
    {
        PostFactory::createOne([
            'title' => $title,
            'status' => $status,
            'publishedAt' => new \DateTimeImmutable($publishedAt),
            'pinned' => $pinned,
            'category' => $category,
        ]);
    }

    private function repository(): PostRepository
    {
        return self::getContainer()->get(PostRepository::class);
    }

    /**
     * @param list<Post> $posts
     *
     * @return list<string>
     */
    private function titles(array $posts): array
    {
        return array_map(static fn (Post $post): string => (string) $post->getTitle(), $posts);
    }
}
