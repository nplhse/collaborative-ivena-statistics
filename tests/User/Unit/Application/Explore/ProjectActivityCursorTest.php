<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Explore;

use App\User\Application\Explore\ProfileActivityType;
use App\User\Application\Explore\ProjectActivity;
use App\User\Application\Explore\ProjectActivityCursor;
use PHPUnit\Framework\TestCase;

final class ProjectActivityCursorTest extends TestCase
{
    public function testEncodeDecodeRoundtrip(): void
    {
        $occurredAt = new \DateTimeImmutable('2024-06-03T12:00:00+00:00');
        $cursor = new ProjectActivityCursor($occurredAt, 50);

        $decoded = ProjectActivityCursor::decode($cursor->encode());

        self::assertEquals($occurredAt, $decoded->occurredAt);
        self::assertSame(50, $decoded->id);
    }

    public function testTryDecodeReturnsNullForInvalidCursors(): void
    {
        self::assertNull(ProjectActivityCursor::tryDecode(null));
        self::assertNull(ProjectActivityCursor::tryDecode(''));
        self::assertNull(ProjectActivityCursor::tryDecode('%%%'));
        self::assertNull(ProjectActivityCursor::tryDecode(base64_encode('not-json')));

        $legacy = base64_encode(json_encode([
            'v' => 2,
            'occurredAt' => '2024-06-03T12:00:00+00:00',
            'id' => 8,
            'joined' => false,
        ], JSON_THROW_ON_ERROR));
        self::assertNull(ProjectActivityCursor::tryDecode($legacy));

        self::assertNull(ProjectActivityCursor::tryDecode(base64_encode(json_encode('scalar', JSON_THROW_ON_ERROR))));
        self::assertNull(ProjectActivityCursor::tryDecode(base64_encode(json_encode([
            'v' => 1,
            'occurredAt' => '2024-06-03T12:00:00+00:00',
        ], JSON_THROW_ON_ERROR))));
        self::assertNull(ProjectActivityCursor::tryDecode(base64_encode(json_encode([
            'v' => 1,
            'occurredAt' => '2024-06-03T12:00:00+00:00',
            'id' => '9',
        ], JSON_THROW_ON_ERROR))));
        self::assertNull(ProjectActivityCursor::tryDecode(base64_encode(json_encode([
            'v' => 1,
            'occurredAt' => '2024-06-03T12:00:00+00:00',
            'id' => 0,
        ], JSON_THROW_ON_ERROR))));
        self::assertNull(ProjectActivityCursor::tryDecode(base64_encode(json_encode([
            'v' => 1,
            'occurredAt' => 'not-a-date',
            'id' => 3,
        ], JSON_THROW_ON_ERROR))));
    }

    public function testFromActivityUsesStableId(): void
    {
        $occurredAt = new \DateTimeImmutable('2024-01-01T00:00:00+00:00');
        $cursor = ProjectActivityCursor::fromActivity(new ProjectActivity(
            occurredAt: $occurredAt,
            type: ProfileActivityType::POST_PUBLISHED,
            stableId: ProjectActivityCursor::padId(9),
            actorUsername: 'alice',
        ));

        self::assertSame(9, $cursor->id);
        self::assertEquals($occurredAt, $cursor->occurredAt);
    }

    public function testFrameIdIsHtmlSafe(): void
    {
        $cursor = new ProjectActivityCursor(
            new \DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            1,
        );
        $frameId = ProjectActivityCursor::frameId($cursor->encode());

        self::assertStringStartsWith('dashboard-activity-after-', $frameId);
        self::assertStringNotContainsString('+', $frameId);
        self::assertStringNotContainsString('/', $frameId);
        self::assertStringNotContainsString('=', $frameId);

        $timelineFrameId = ProjectActivityCursor::frameId(
            $cursor->encode(),
            ProjectActivityCursor::FRAME_PREFIX_TIMELINE,
        );
        self::assertStringStartsWith('activity-timeline-after-', $timelineFrameId);
        self::assertStringNotContainsString('+', $timelineFrameId);
    }
}
