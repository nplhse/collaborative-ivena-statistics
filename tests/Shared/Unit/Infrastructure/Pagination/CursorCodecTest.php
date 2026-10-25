<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Pagination;

use App\Shared\Infrastructure\Pagination\CursorCodec;
use PHPUnit\Framework\TestCase;

final class CursorCodecTest extends TestCase
{
    public function testEncodeDecodeRoundtrip(): void
    {
        $codec = new CursorCodec();

        $cursor = $codec->encode('arrivalAt', 'desc', '2026-01-01T00:00:00+00:00', 42);
        $decoded = $codec->decode($cursor);

        self::assertSame('arrivalAt', $decoded['sortBy']);
        self::assertSame('desc', $decoded['orderBy']);
        self::assertSame('2026-01-01T00:00:00+00:00', $decoded['sortValue']);
        self::assertSame(42, $decoded['id']);
        self::assertTrue($decoded['forward']);
    }

    public function testEncodesBackwardDirection(): void
    {
        $codec = new CursorCodec();

        $decoded = $codec->decode($codec->encode('age', 'asc', 40, 7, forward: false));

        self::assertFalse($decoded['forward']);
        self::assertSame(40, $decoded['sortValue']);
    }

    public function testDecodesVersionOneCursorsAsForward(): void
    {
        $codec = new CursorCodec();
        $legacy = base64_encode(json_encode([
            'v' => 1,
            'sortBy' => 'arrivalAt',
            'orderBy' => 'desc',
            'sortValue' => '2026-01-01T00:00:00+00:00',
            'id' => 42,
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($codec->decode($legacy)['forward']);
    }

    public function testDecodeRejectsUnknownDirection(): void
    {
        $codec = new CursorCodec();
        $cursor = base64_encode(json_encode([
            'v' => 2,
            'sortBy' => 'age',
            'orderBy' => 'asc',
            'sortValue' => 40,
            'id' => 7,
            'direction' => 'sideways',
        ], JSON_THROW_ON_ERROR));

        $this->expectException(\InvalidArgumentException::class);
        $codec->decode($cursor);
    }

    public function testDecodeRejectsUnsupportedVersion(): void
    {
        $codec = new CursorCodec();
        $cursor = base64_encode(json_encode([
            'v' => 3,
            'sortBy' => 'age',
            'orderBy' => 'asc',
            'sortValue' => 40,
            'id' => 7,
        ], JSON_THROW_ON_ERROR));

        $this->expectException(\InvalidArgumentException::class);
        $codec->decode($cursor);
    }

    public function testDecodeRejectsInvalidBase64(): void
    {
        $codec = new CursorCodec();

        $this->expectException(\InvalidArgumentException::class);
        $codec->decode('%%%');
    }

    public function testDecodeRejectsInvalidJsonPayload(): void
    {
        $codec = new CursorCodec();
        $cursor = base64_encode('not-json');

        $this->expectException(\InvalidArgumentException::class);
        $codec->decode($cursor);
    }

    public function testDecodeRejectsMissingKeys(): void
    {
        $codec = new CursorCodec();
        $cursor = base64_encode(json_encode(['v' => 1], JSON_THROW_ON_ERROR));

        $this->expectException(\InvalidArgumentException::class);
        $codec->decode($cursor);
    }
}
