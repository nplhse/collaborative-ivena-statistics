<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Explore;

use App\User\Application\Explore\PostPublishedActivitySlugs;
use App\User\Application\Explore\ProfileActivity;
use App\User\Application\Explore\ProfileActivityType;
use App\User\Application\Explore\ProjectActivity;
use PHPUnit\Framework\TestCase;

final class PostPublishedActivitySlugsTest extends TestCase
{
    public function testFromCollectsUniquePostPublishedSlugs(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-08-01 12:00:00');
        $activities = [
            new ProjectActivity(
                occurredAt: $occurredAt,
                type: ProfileActivityType::POST_PUBLISHED,
                stableId: '1',
                actorUsername: 'alice',
                postSlug: 'first-post',
            ),
            new ProjectActivity(
                occurredAt: $occurredAt,
                type: ProfileActivityType::COMMENT_CREATED,
                stableId: '2',
                actorUsername: 'alice',
                postSlug: 'ignored-comment-post',
            ),
            new ProfileActivity(
                occurredAt: $occurredAt,
                type: ProfileActivityType::POST_PUBLISHED,
                stableId: '3',
                postSlug: 'first-post',
            ),
            new ProfileActivity(
                occurredAt: $occurredAt,
                type: ProfileActivityType::POST_PUBLISHED,
                stableId: '4',
                postSlug: 'second-post',
            ),
            new ProfileActivity(
                occurredAt: $occurredAt,
                type: ProfileActivityType::POST_PUBLISHED,
                stableId: '5',
                postSlug: null,
            ),
            new ProfileActivity(
                occurredAt: $occurredAt,
                type: ProfileActivityType::POST_PUBLISHED,
                stableId: '6',
                postSlug: '',
            ),
        ];

        self::assertSame(['first-post', 'second-post'], PostPublishedActivitySlugs::from($activities));
        self::assertSame([], PostPublishedActivitySlugs::from([]));
    }
}
