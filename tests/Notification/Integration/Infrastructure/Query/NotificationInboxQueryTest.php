<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\Infrastructure\Query;

use App\Notification\Application\Contract\NotificationInboxQueryInterface;
use App\Notification\Application\Inbox\InboxCursor;
use App\Notification\Application\Inbox\InboxEntry;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Notification\Infrastructure\Factory\NotificationRecipientStateFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Notification\NotificationTestState;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;

final class NotificationInboxQueryTest extends DatabaseKernelTestCase
{
    public function testStateDefaultsToEmptyAndReadsStoredValues(): void
    {
        $query = $this->query();
        $user = UserFactory::createOne();
        $userId = (int) $user->getId();

        self::assertSame(0, $query->state($userId)->version);
        self::assertSame(0, $query->state($userId)->unreadCount);

        NotificationRecipientStateFactory::createOne(['user' => $user, 'version' => 7, 'unreadCount' => 3]);

        self::assertSame(7, $query->state($userId)->version);
        self::assertSame(3, $query->state($userId)->unreadCount);
    }

    public function testLatestReturnsNewestVisibleOwnEntries(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $ids = [];
        for ($i = 1; $i <= 6; ++$i) {
            $ids[$i] = (int) NotificationFactory::createOne(['recipient' => $user, 'type' => 'test.n'.$i, 'createdAt' => new \DateTimeImmutable('2026-10-0'.$i.' 10:00:00')])->getId();
        }
        $this->state()->markRemoved(
            NotificationFactory::createOne(['recipient' => $user, 'type' => 'test.removed', 'createdAt' => new \DateTimeImmutable('2026-10-09 10:00:00')]),
            new \DateTimeImmutable('2026-10-09 11:00:00'),
        );
        NotificationFactory::createOne(['recipient' => $user, 'type' => 'test.email_only', 'inAppVisible' => false, 'createdAt' => new \DateTimeImmutable('2026-10-09 10:00:00')]);
        NotificationFactory::createOne(['recipient' => $other, 'type' => 'test.other', 'createdAt' => new \DateTimeImmutable('2026-10-09 10:00:00')]);

        $latest = array_map(static fn (InboxEntry $entry): int => $entry->id, $this->query()->latest((int) $user->getId(), 5));

        self::assertSame([$ids[6], $ids[5], $ids[4], $ids[3], $ids[2]], $latest);
    }

    public function testPagesWithKeysetAndOrdersEqualTimestampsById(): void
    {
        $user = UserFactory::createOne();
        $sameTime = new \DateTimeImmutable('2026-10-10 10:00:00');
        $ids = [];
        for ($i = 1; $i <= 5; ++$i) {
            $ids[] = (int) NotificationFactory::createOne(['recipient' => $user, 'createdAt' => $sameTime])->getId();
        }
        $userId = (int) $user->getId();

        $first = $this->query()->page($userId, size: 2);
        $second = $this->query()->page($userId, before: $first->olderCursor, size: 2);
        $third = $this->query()->page($userId, before: $second->olderCursor, size: 2);

        $descending = array_reverse($ids);
        self::assertSame(\array_slice($descending, 0, 2), $this->ids($first->entries));
        self::assertSame(\array_slice($descending, 2, 2), $this->ids($second->entries));
        self::assertSame(\array_slice($descending, 4, 1), $this->ids($third->entries));
        self::assertInstanceOf(InboxCursor::class, $first->olderCursor);
        self::assertNull($first->newerCursor, 'the first page has nothing newer');
        self::assertInstanceOf(InboxCursor::class, $second->newerCursor);
        self::assertNull($third->olderCursor);

        $backToSecond = $this->query()->page($userId, after: $third->newerCursor, size: 2);
        $backToFirst = $this->query()->page($userId, after: $backToSecond->newerCursor, size: 2);
        self::assertSame($this->ids($second->entries), $this->ids($backToSecond->entries), 'newer pages are returned newest first');
        self::assertSame($this->ids($first->entries), $this->ids($backToFirst->entries));
        self::assertNull($backToFirst->newerCursor);
        self::assertInstanceOf(InboxCursor::class, $backToFirst->olderCursor);
        self::assertSame(
            $this->ids($third->entries),
            $this->ids($this->query()->page($userId, before: $second->olderCursor, after: $first->olderCursor, size: 2)->entries),
            'before wins over after',
        );
    }

    public function testPageBeyondTheLastEntryIsEmpty(): void
    {
        $user = UserFactory::createOne();
        $notification = NotificationFactory::createOne(['recipient' => $user, 'createdAt' => new \DateTimeImmutable('2026-10-10 10:00:00')]);
        $cursor = new InboxCursor(new \DateTimeImmutable('2026-10-10 10:00:00'), (int) $notification->getId());

        $page = $this->query()->page((int) $user->getId(), before: $cursor);

        self::assertSame([], $page->entries);
        self::assertNull($page->olderCursor);
        self::assertNull($page->newerCursor);
        self::assertSame([], $this->query()->page((int) $user->getId(), after: $cursor)->entries);
    }

    public function testPageCanBeLimitedToUnreadEntries(): void
    {
        $user = UserFactory::createOne();
        $start = new \DateTimeImmutable('2026-10-10 10:00:00');
        $unread = [];
        for ($i = 0; $i < 5; ++$i) {
            $notification = NotificationFactory::createOne(['recipient' => $user, 'createdAt' => $start->modify('+'.$i.' hours')]);
            if (0 === $i % 2) {
                $unread[] = (int) $notification->getId();
            } else {
                $this->state()->markRead($notification, $start->modify('+1 day'));
            }
        }
        $userId = (int) $user->getId();

        $first = $this->query()->page($userId, size: 2, unreadOnly: true);
        $second = $this->query()->page($userId, before: $first->olderCursor, size: 2, unreadOnly: true);

        self::assertSame(\array_slice(array_reverse($unread), 0, 2), array_map(static fn (InboxEntry $e): int => $e->id, $first->entries));
        self::assertSame(\array_slice(array_reverse($unread), 2, 1), array_map(static fn (InboxEntry $e): int => $e->id, $second->entries));
        self::assertNull($second->olderCursor);
        self::assertSame($this->ids($first->entries), $this->ids($this->query()->page($userId, after: $second->newerCursor, size: 2, unreadOnly: true)->entries));
        self::assertCount(5, $this->query()->page($userId, size: 10)->entries);
    }

    public function testFindHydratesEntryAndIgnoresForeignEntries(): void
    {
        $user = UserFactory::createOne();
        $notification = NotificationFactory::createOne([
            'recipient' => $user,
            'parameters' => ['importName' => 'Januar', 'rows' => 12],
            'actionRoute' => 'app_import_show',
            'actionParameters' => ['id' => 7],
            'accessScope' => NotificationAccessScope::Role,
            'requiredRole' => 'ROLE_ADMIN',
            'scopeReferenceId' => 3,
        ]);
        $notificationId = (int) $notification->getId();
        $this->state()->markRead($notification, new \DateTimeImmutable('2026-10-22 09:00:00'));

        $entry = $this->query()->find((int) $user->getId(), $notificationId);

        self::assertNotNull($entry);
        self::assertSame(['importName' => 'Januar', 'rows' => 12], $entry->parameters);
        self::assertSame('app_import_show', $entry->actionRoute);
        self::assertSame(['id' => 7], $entry->actionParameters);
        self::assertSame(NotificationAccessScope::Role, $entry->accessScope);
        self::assertSame('ROLE_ADMIN', $entry->requiredRole);
        self::assertSame(3, $entry->scopeReferenceId);
        self::assertTrue($entry->isRead());
        self::assertNull($this->query()->find((int) UserFactory::createOne()->getId(), $notificationId));
    }

    public function testIgnoresStoredParametersThatAreNotAnObject(): void
    {
        $user = UserFactory::createOne();
        $notification = NotificationFactory::createOne(['recipient' => $user]);
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE notification SET parameters = :scalar, action_parameters = :list WHERE id = :id',
            ['scalar' => '"text"', 'list' => '{"id": 3, "nested": {"a": 1}, "0": "x"}', 'id' => $notification->getId()],
        );

        $entry = $this->query()->find((int) $user->getId(), (int) $notification->getId());

        self::assertNotNull($entry);
        self::assertSame([], $entry->parameters);
        self::assertSame(['id' => 3], $entry->actionParameters);
    }

    /**
     * @param list<InboxEntry> $entries
     *
     * @return list<int>
     */
    private function ids(array $entries): array
    {
        return array_map(static fn (InboxEntry $entry): int => $entry->id, $entries);
    }

    private function state(): NotificationTestState
    {
        return new NotificationTestState(self::getContainer()->get(Connection::class));
    }

    private function query(): NotificationInboxQueryInterface
    {
        return self::getContainer()->get(NotificationInboxQueryInterface::class);
    }
}
