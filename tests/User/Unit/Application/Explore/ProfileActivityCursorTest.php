<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Explore;

use App\User\Application\Explore\ProfileActivity;
use App\User\Application\Explore\ProfileActivityCursor;
use App\User\Application\Explore\ProfileActivityType;
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

    public function testTryDecodeReturnsNullForInvalidAndLegacyCursors(): void
    {
        self::assertNull(ProfileActivityCursor::tryDecode(null));
        self::assertNull(ProfileActivityCursor::tryDecode(''));
        self::assertNull(ProfileActivityCursor::tryDecode('%%%'));
        self::assertNull(ProfileActivityCursor::tryDecode(base64_encode('not-json')));

        $legacy = base64_encode(json_encode([
            'v' => 1,
            'occurredAt' => '2024-06-03T12:00:00+00:00',
            'type' => 'post_published',
            'stableId' => ProfileActivityCursor::padId(8),
        ], JSON_THROW_ON_ERROR));
        self::assertNull(ProfileActivityCursor::tryDecode($legacy));
    }

    public function testFromActivitySetsJoinedFlag(): void
    {
        $occurredAt = new \DateTimeImmutable('2024-01-01T00:00:00+00:00');
        $joined = ProfileActivityCursor::fromActivity(new ProfileActivity(
            occurredAt: $occurredAt,
            type: ProfileActivityType::JOINED,
            stableId: ProfileActivityCursor::padId(7),
        ));
        $post = ProfileActivityCursor::fromActivity(new ProfileActivity(
            occurredAt: $occurredAt,
            type: ProfileActivityType::POST_PUBLISHED,
            stableId: ProfileActivityCursor::padId(9),
        ));

        self::assertTrue($joined->joined);
        self::assertSame(7, $joined->id);
        self::assertFalse($post->joined);
        self::assertSame(9, $post->id);
    }

    public function testFrameIdIsHtmlSafe(): void
    {
        $cursor = new ProfileActivityCursor(
            new \DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            1,
            true,
        );
        $frameId = ProfileActivityCursor::frameId($cursor->encode());

        self::assertStringStartsWith('profile-activity-after-', $frameId);
        self::assertStringNotContainsString('+', $frameId);
        self::assertStringNotContainsString('/', $frameId);
        self::assertStringNotContainsString('=', $frameId);
    }
}
