<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Audit;

use App\Admin\Application\Audit\AuditTimelineCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditTimelineCursorTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $cursor = new AuditTimelineCursor(new \DateTimeImmutable('2026-11-13 10:00:05'), 42);

        $decoded = AuditTimelineCursor::tryDecode($cursor->encode());

        self::assertNotNull($decoded);
        self::assertSame('2026-11-13 10:00:05', $decoded->occurredAt->format('Y-m-d H:i:s'));
        self::assertSame(42, $decoded->id);
    }

    #[DataProvider('invalidCursors')]
    public function testInvalidCursorIsIgnored(?string $cursor): void
    {
        self::assertNull(AuditTimelineCursor::tryDecode($cursor));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function invalidCursors(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'not base64' => ['%%%'];
        yield 'not json' => [base64_encode('nope')];
        yield 'wrong version' => [base64_encode('{"v":2,"at":"2026-11-13 10:00:00","id":1}')];
        yield 'id zero' => [base64_encode('{"v":1,"at":"2026-11-13 10:00:00","id":0}')];
        yield 'bad date' => [base64_encode('{"v":1,"at":"yesterday","id":1}')];
        yield 'too long' => [str_repeat('a', 201)];
    }
}
