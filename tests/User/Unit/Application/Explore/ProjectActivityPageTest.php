<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Explore;

use App\User\Application\Explore\ProfileActivityType;
use App\User\Application\Explore\ProjectActivity;
use App\User\Application\Explore\ProjectActivityCursor;
use App\User\Application\Explore\ProjectActivityPage;
use PHPUnit\Framework\TestCase;

final class ProjectActivityPageTest extends TestCase
{
    public function testNextFrameIdIsNullWhenThereIsNoCursor(): void
    {
        $page = new ProjectActivityPage([], null);

        self::assertFalse($page->hasMore());
        self::assertNull($page->nextFrameId());
    }

    public function testNextFrameIdUsesEncodedCursor(): void
    {
        $activity = new ProjectActivity(
            occurredAt: new \DateTimeImmutable('2026-04-01T12:00:00+00:00'),
            type: ProfileActivityType::JOINED,
            stableId: ProjectActivityCursor::padId(7),
            actorUsername: 'alice',
        );
        $cursor = ProjectActivityCursor::fromActivity($activity)->encode();
        $page = new ProjectActivityPage([$activity], $cursor);

        self::assertTrue($page->hasMore());
        self::assertSame(ProjectActivityCursor::frameId($cursor), $page->nextFrameId());
        self::assertContains(ProfileActivityType::FIRST_IMPORT->value, array_map(
            static fn ($type) => $type->value,
            ProjectActivityPage::feedTypes(),
        ));
    }
}
