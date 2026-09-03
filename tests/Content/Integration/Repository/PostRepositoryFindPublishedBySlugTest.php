<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Repository;

use App\Content\Domain\Entity\PostTag;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PostCategoryFactory;
use App\Content\Infrastructure\Factory\PostCommentFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Content\Infrastructure\Factory\PostTagFactory;
use App\Content\Infrastructure\Repository\PostRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class PostRepositoryFindPublishedBySlugTest extends DatabaseKernelTestCase
{
    public function testFindPublishedBySlugHydratesAllTagsInAlphabeticalOrder(): void
    {
        $category = PostCategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);
        $zebra = PostTagFactory::createOne(['name' => 'Zebra', 'slug' => 'zebra']);
        $alpha = PostTagFactory::createOne(['name' => 'Alpha', 'slug' => 'alpha']);
        $middle = PostTagFactory::createOne(['name' => 'Middle', 'slug' => 'middle']);

        $post = PostFactory::createOne([
            'title' => 'Multi Tag Post',
            'slug' => 'multi-tag-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
            'category' => $category,
            'tags' => [$zebra, $alpha, $middle],
        ]);
        PostCommentFactory::createOne(['post' => $post, 'content' => 'First comment']);
        PostCommentFactory::createOne(['post' => $post, 'content' => 'Second comment']);

        PostFactory::createOne([
            'title' => 'Untagged Post',
            'slug' => 'untagged-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-2 hours'),
            'category' => $category,
            'tags' => [],
        ]);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->clear();

        $repository = self::getContainer()->get(PostRepository::class);

        $loaded = $repository->findPublishedBySlug('multi-tag-post');
        self::assertNotNull($loaded);
        self::assertCount(3, $loaded->getTags());
        self::assertSame(
            ['Alpha', 'Middle', 'Zebra'],
            array_map(
                static fn (PostTag $tag): ?string => $tag->getName(),
                $loaded->getSortedTags(),
            ),
        );

        $untagged = $repository->findPublishedBySlug('untagged-post');
        self::assertNotNull($untagged);
        self::assertCount(0, $untagged->getTags());
    }
}
