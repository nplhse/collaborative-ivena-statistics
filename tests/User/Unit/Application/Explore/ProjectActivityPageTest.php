<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Explore;

use App\User\Application\Explore\ProfileActivityType;
use App\User\Application\Explore\ProjectActivity;
use App\User\Application\Explore\ProjectActivityCursor;
use App\User\Application\Explore\ProjectActivityPage;
use App\User\Domain\Enum\UserActivityType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectActivityPage::class)]
final class ProjectActivityPageTest extends TestCase
{
    public function testLastPageHasNoMoreAndNoNextFrameId(): void
    {
        $page = new ProjectActivityPage([], null);

        $result = [$page->hasMore(), $page->nextFrameId()];

        self::assertSame([false, null], $result);
    }

    public function testPageWithACursorHasMore(): void
    {
        $page = new ProjectActivityPage([$this->activity()], 'ab+c/d==');

        $hasMore = $page->hasMore();

        self::assertTrue($hasMore);
    }

    public function testNextFrameIdUsesTheDashboardPrefixByDefault(): void
    {
        $page = new ProjectActivityPage([$this->activity()], 'ab+c/d==');

        $frameId = $page->nextFrameId();

        self::assertSame('dashboard-activity-after-ab-c_d', $frameId);
    }

    public function testNextFrameIdUsesTheGivenPrefix(): void
    {
        $page = new ProjectActivityPage([$this->activity()], 'ab+c/d==');

        $frameId = $page->nextFrameId(ProjectActivityCursor::FRAME_PREFIX_TIMELINE);

        self::assertSame('activity-timeline-after-ab-c_d', $frameId);
    }

    public function testFeedShowsNewActivityButNoRemovedAccessOrOwnership(): void
    {
        $types = ProjectActivityPage::feedTypes();

        // Missing on purpose: hospital_disassociated and hospital_owner_revoked (only on the hospital page, #488).
        self::assertSame([
            UserActivityType::JOINED,
            UserActivityType::FIRST_IMPORT,
            UserActivityType::IMPORT_MILESTONE,
            UserActivityType::POST_PUBLISHED,
            UserActivityType::COMMENT_CREATED,
            UserActivityType::HOSPITAL_ASSOCIATED,
            UserActivityType::HOSPITAL_OWNER_GRANTED,
        ], $types);
    }

    private function activity(): ProjectActivity
    {
        return new ProjectActivity(
            occurredAt: new \DateTimeImmutable('2026-04-01T12:00:00+00:00'),
            type: ProfileActivityType::JOINED,
            stableId: ProjectActivityCursor::padId(7),
            actorUsername: 'alice',
        );
    }
}
