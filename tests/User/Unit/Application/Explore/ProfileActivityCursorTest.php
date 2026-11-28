<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Explore;

use App\User\Application\Explore\ProfileActivity;
use App\User\Application\Explore\ProfileActivityCursor;
use App\User\Application\Explore\ProfileActivityType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileActivityCursorTest extends TestCase
{
    public function testEncodeDecodeRoundtrip(): void
    {
        $occurredAt = new \DateTimeImmutable('2024-06-03T12:00:00+00:00');
        $cursor = new ProfileActivityCursor($occurredAt, 50, false);

        $decoded = ProfileActivityCursor::decode($cursor->encode());

        self::assertEquals($occurredAt, $decoded->occurredAt);
        self::assertSame(50, $decoded->id);
        self::assertFalse($decoded->joined);
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
        // Version 1 paged by type and padded stable id; version 2 pages by id and the joined flag, so an old cursor
        // from a bookmarked page would point to the wrong place and starts on the first page instead.
        yield 'legacy version 1' => [self::encode([
            'v' => 1,
            'occurredAt' => '2024-06-03T12:00:00+00:00',
            'type' => 'post_published',
            'stableId' => ProfileActivityCursor::padId(8),
        ])];
    }

    #[DataProvider('invalidCursors')]
    public function testTryDecodeReturnsNullForInvalidAndLegacyCursors(?string $cursor): void
    {
        $decoded = ProfileActivityCursor::tryDecode($cursor);

        self::assertNull($decoded);
    }

    /**
     * @return iterable<string, array{ProfileActivityType, int, bool}>
     */
    public static function activities(): iterable
    {
        yield 'joined' => [ProfileActivityType::JOINED, 7, true];
        yield 'post published' => [ProfileActivityType::POST_PUBLISHED, 9, false];
    }

    #[DataProvider('activities')]
    public function testFromActivityTakesTheIdAndSetsTheJoinedFlag(ProfileActivityType $type, int $id, bool $joined): void
    {
        $activity = new ProfileActivity(
            occurredAt: new \DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            type: $type,
            stableId: ProfileActivityCursor::padId($id),
        );

        $cursor = ProfileActivityCursor::fromActivity($activity);

        self::assertSame([$id, $joined], [$cursor->id, $cursor->joined]);
    }

    /**
     * "+" and "/" become "-" and "_", the "=" padding is dropped, so the id is valid in HTML and in a URL.
     */
    public function testFrameIdIsHtmlSafe(): void
    {
        $frameId = ProfileActivityCursor::frameId('ab+c/d==');

        self::assertSame('profile-activity-after-ab-c_d', $frameId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
