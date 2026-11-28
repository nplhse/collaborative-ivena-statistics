<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Explore;

use App\User\Application\Explore\PostPublishedActivitySlugs;
use App\User\Application\Explore\ProfileActivity;
use App\User\Application\Explore\ProfileActivityType;
use App\User\Application\Explore\ProjectActivity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PostPublishedActivitySlugs::class)]
final class PostPublishedActivitySlugsTest extends TestCase
{
    public function testFromCollectsUniquePostPublishedSlugs(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-08-01 12:00:00');
        $activities = [
            // Counts: a published post in the project feed.
            new ProjectActivity(
                occurredAt: $occurredAt,
                type: ProfileActivityType::POST_PUBLISHED,
                stableId: '1',
                actorUsername: 'alice',
                postSlug: 'first-post',
            ),
            // Skipped: a comment carries the slug of the post it belongs to, but is no published post.
            new ProjectActivity(
                occurredAt: $occurredAt,
                type: ProfileActivityType::COMMENT_CREATED,
                stableId: '2',
                actorUsername: 'alice',
                postSlug: 'ignored-comment-post',
            ),
            // Skipped as a duplicate: the same post in the profile feed.
            new ProfileActivity(
                occurredAt: $occurredAt,
                type: ProfileActivityType::POST_PUBLISHED,
                stableId: '3',
                postSlug: 'first-post',
            ),
            // Counts: a second post, from the profile feed.
            new ProfileActivity(
                occurredAt: $occurredAt,
                type: ProfileActivityType::POST_PUBLISHED,
                stableId: '4',
                postSlug: 'second-post',
            ),
            // Skipped: no slug.
            new ProfileActivity(
                occurredAt: $occurredAt,
                type: ProfileActivityType::POST_PUBLISHED,
                stableId: '5',
                postSlug: null,
            ),
            // Skipped: an empty slug.
            new ProfileActivity(
                occurredAt: $occurredAt,
                type: ProfileActivityType::POST_PUBLISHED,
                stableId: '6',
                postSlug: '',
            ),
        ];

        $slugs = PostPublishedActivitySlugs::from($activities);

        self::assertSame(['first-post', 'second-post'], $slugs);
    }

    public function testFromWithoutActivitiesReturnsNoSlugs(): void
    {
        $slugs = PostPublishedActivitySlugs::from([]);

        self::assertSame([], $slugs);
    }
}
