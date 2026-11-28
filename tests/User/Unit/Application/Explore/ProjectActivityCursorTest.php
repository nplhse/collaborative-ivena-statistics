<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Explore;

use App\User\Application\Explore\ProfileActivityType;
use App\User\Application\Explore\ProjectActivity;
use App\User\Application\Explore\ProjectActivityCursor;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{?string}>
     */
    public static function invalidCursors(): iterable
    {
        yield 'none' => [null];
        yield 'empty' => [''];
        yield 'not base64' => ['%%%'];
        yield 'not JSON' => [base64_encode('not-json')];
        // Version 2 is the profile feed's cursor (with the joined flag, see ProfileActivityCursor); the project feed
        // pages by id only and must not accept it.
        yield 'profile feed cursor (version 2)' => [self::encode(['v' => 2, 'occurredAt' => '2024-06-03T12:00:00+00:00', 'id' => 8, 'joined' => false])];
        yield 'scalar payload' => [self::encode('scalar')];
        yield 'missing id' => [self::encode(['v' => 1, 'occurredAt' => '2024-06-03T12:00:00+00:00'])];
        yield 'id as string' => [self::encode(['v' => 1, 'occurredAt' => '2024-06-03T12:00:00+00:00', 'id' => '9'])];
        yield 'id below 1' => [self::encode(['v' => 1, 'occurredAt' => '2024-06-03T12:00:00+00:00', 'id' => 0])];
        yield 'invalid date' => [self::encode(['v' => 1, 'occurredAt' => 'not-a-date', 'id' => 3])];
    }

    #[DataProvider('invalidCursors')]
    public function testTryDecodeReturnsNullForInvalidCursors(?string $cursor): void
    {
        $decoded = ProjectActivityCursor::tryDecode($cursor);

        self::assertNull($decoded);
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function framePrefixes(): iterable
    {
        yield 'dashboard' => [ProjectActivityCursor::FRAME_PREFIX_DASHBOARD, 'dashboard-activity-after-ab-c_d'];
        yield 'timeline' => [ProjectActivityCursor::FRAME_PREFIX_TIMELINE, 'activity-timeline-after-ab-c_d'];
    }

    /**
     * "+" and "/" become "-" and "_", the "=" padding is dropped, so the id is valid in HTML and in a URL.
     */
    #[DataProvider('framePrefixes')]
    public function testFrameIdIsHtmlSafe(string $prefix, string $expected): void
    {
        $frameId = ProjectActivityCursor::frameId('ab+c/d==', $prefix);

        self::assertSame($expected, $frameId);
    }

    public function testFrameIdUsesTheDashboardPrefixByDefault(): void
    {
        $frameId = ProjectActivityCursor::frameId('ab+c/d==');

        self::assertSame('dashboard-activity-after-ab-c_d', $frameId);
    }

    private static function encode(mixed $payload): string
    {
        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
