<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Adapter;

use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Adapter\DoctrinePublishedPostPreviewMap;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Contract\PublishedPostPreviewMapInterface;

final class DoctrinePublishedPostPreviewMapTest extends DatabaseKernelTestCase
{
    public function testForSlugsReturnsPublishedPreviewsAndOmitsDraftFutureAndEmpty(): void
    {
        PostFactory::createOne([
            'title' => 'Live Post',
            'slug' => 'live-post',
            'content' => '<p>Live intro</p><script>alert(1)</script><p>More</p>',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 day'),
        ]);
        PostFactory::createOne([
            'title' => 'Second Live',
            'slug' => 'second-live',
            'content' => '<p>Second intro</p>',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-2 hours'),
        ]);
        PostFactory::createOne([
            'title' => 'Draft Post',
            'slug' => 'draft-post',
            'content' => '<p>Draft body</p>',
            'status' => PostStatus::DRAFT,
            'publishedAt' => null,
        ]);
        PostFactory::createOne([
            'title' => 'Scheduled Post',
            'slug' => 'scheduled-post',
            'content' => '<p>Future body</p>',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('+2 days'),
        ]);
        PostFactory::createOne([
            'title' => 'Empty Post',
            'slug' => 'empty-post',
            'content' => '',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-3 hours'),
        ]);

        $map = self::getContainer()->get(PublishedPostPreviewMapInterface::class);
        self::assertInstanceOf(DoctrinePublishedPostPreviewMap::class, $map);

        $previews = $map->forSlugs([
            'live-post',
            'second-live',
            'draft-post',
            'scheduled-post',
            'empty-post',
            'missing-post',
            null,
            '',
        ]);

        self::assertArrayHasKey('live-post', $previews);
        self::assertArrayHasKey('second-live', $previews);
        self::assertStringContainsString('Live intro', $previews['live-post']);
        self::assertStringNotContainsString('<script>', $previews['live-post']);
        self::assertStringNotContainsString('More', $previews['live-post']);
        self::assertStringContainsString('Second intro', $previews['second-live']);
        self::assertArrayNotHasKey('draft-post', $previews);
        self::assertArrayNotHasKey('scheduled-post', $previews);
        self::assertArrayNotHasKey('empty-post', $previews);
        self::assertArrayNotHasKey('missing-post', $previews);
    }

    public function testForSlugsReturnsEmptyMapForNoSlugs(): void
    {
        $map = self::getContainer()->get(PublishedPostPreviewMapInterface::class);

        self::assertSame([], $map->forSlugs([]));
    }
}
