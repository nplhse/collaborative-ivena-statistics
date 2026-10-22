<?php

declare(strict_types=1);

namespace App\Tests\Notification\Unit\Application\Inbox;

use App\Notification\Application\Inbox\InboxCursor;
use App\Notification\Application\Inbox\InboxEntry;
use App\Notification\Domain\Enum\NotificationAccessScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InboxCursorTest extends TestCase
{
    public function testRoundTripsAndIsUrlSafe(): void
    {
        $cursor = new InboxCursor(new \DateTimeImmutable('2026-10-22 13:45:10'), 4711);

        $encoded = $cursor->encode();
        $decoded = InboxCursor::tryDecode($encoded);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
        self::assertInstanceOf(InboxCursor::class, $decoded);
        self::assertSame('2026-10-22 13:45:10', $decoded->createdAt->format('Y-m-d H:i:s'));
        self::assertSame(4711, $decoded->id);
    }

    public function testAfterUsesPositionOfEntry(): void
    {
        $entry = new InboxEntry(
            id: 9,
            translationDomain: 'notification',
            titleKey: 't',
            messageKey: 'm',
            parameters: [],
            actionRoute: null,
            actionParameters: [],
            accessScope: NotificationAccessScope::None,
            scopeReferenceId: null,
            requiredRole: null,
            createdAt: new \DateTimeImmutable('2026-10-01 08:00:00'),
            readAt: null,
        );

        $cursor = InboxCursor::at($entry);

        self::assertSame(9, $cursor->id);
        self::assertEquals($entry->createdAt, $cursor->createdAt);
        self::assertFalse($entry->isRead());
    }

    #[DataProvider('invalidCursors')]
    public function testRejectsInvalidCursor(?string $cursor): void
    {
        self::assertNull(InboxCursor::tryDecode($cursor));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function invalidCursors(): iterable
    {
        $encode = static fn (array $payload): string => rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');

        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'not base64' => ['%%%'];
        yield 'not json' => [rtrim(base64_encode('not json'), '=')];
        yield 'not an object' => [$encode([1, 2])];
        yield 'wrong version' => [$encode(['v' => 2, 'createdAt' => '2026-10-22 13:45:10', 'id' => 1])];
        yield 'missing id' => [$encode(['v' => 1, 'createdAt' => '2026-10-22 13:45:10'])];
        yield 'id not positive' => [$encode(['v' => 1, 'createdAt' => '2026-10-22 13:45:10', 'id' => 0])];
        yield 'invalid date' => [$encode(['v' => 1, 'createdAt' => 'yesterday', 'id' => 1])];
    }
}
