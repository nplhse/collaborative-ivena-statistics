<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Repository;

use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PostCategoryFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Content\Infrastructure\Factory\PostTagFactory;
use App\Content\Infrastructure\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PostRepositoryFindPublishedForIndexTest extends KernelTestCase
{
    use Factories;

    public function testFindPublishedForIndexReturnsFewerPostsWhenTagsMultiplyJoinedRows(): void
    {
        self::bootKernel();

        $category = PostCategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);
        $manyTags = PostTagFactory::createMany(3);
        $singleTag = PostTagFactory::createOne(['name' => 'Solo', 'slug' => 'solo']);

        PostFactory::createOne([
            'title' => 'Post Newest',
            'slug' => 'post-newest',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
            'category' => $category,
            'tags' => $manyTags,
        ]);
        PostFactory::createOne([
            'title' => 'Post Middle',
            'slug' => 'post-middle',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-2 hours'),
            'category' => $category,
            'tags' => $manyTags,
        ]);
        PostFactory::createOne([
            'title' => 'Post Oldest',
            'slug' => 'post-oldest',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-3 hours'),
            'category' => $category,
            'tags' => [$singleTag],
        ]);

        $repository = self::getContainer()->get(PostRepository::class);
        $posts = $repository->findPublishedForIndex(5);

        self::assertCount(
            3,
            $posts,
            'Expected all 3 published posts; tag join + SQL LIMIT can return fewer unique posts.',
        );
    }
}
