<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\Infrastructure\Inbox;

use App\Notification\Application\Inbox\ClaimedToast;
use App\Notification\Domain\Entity\Notification;
use App\Notification\Domain\Enum\NotificationPriority;
use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Notification\Infrastructure\Inbox\DbalNotificationToastClaimer;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Notification\NotificationTestState;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;

final class DbalNotificationToastClaimerTest extends DatabaseKernelTestCase
{
    private Connection $connection;
    private DbalNotificationToastClaimer $claimer;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->claimer = new DbalNotificationToastClaimer($this->connection);
        $this->now = new \DateTimeImmutable('2026-10-22 15:00:00');
    }

    public function testClaimsPendingToastsOnceOldestFirst(): void
    {
        $user = UserFactory::createOne();
        $newer = $this->toast($user, '2026-10-22 14:59:00', ['priority' => NotificationPriority::High, 'parameters' => ['importName' => 'Januar']]);
        $older = $this->toast($user, '2026-10-22 14:58:00');

        $claimed = $this->claimer->claim((int) $user->getId(), $this->now, 10);

        self::assertSame([$older->getId(), $newer->getId()], array_map(static fn (ClaimedToast $toast): int => $toast->entry->id, $claimed));
        self::assertSame(NotificationPriority::High, $claimed[1]->priority);
        self::assertSame(['importName' => 'Januar'], $claimed[1]->entry->parameters);
        self::assertSame('2026-10-22 15:00:00', $this->connection->fetchOne('SELECT toast_claimed_at FROM notification WHERE id = ?', [$older->getId()]));
        self::assertSame([], $this->claimer->claim((int) $user->getId(), $this->now, 10), 'a toast is handed out only once');
    }

    public function testSkipsExpiredReadRemovedHiddenAndForeignToasts(): void
    {
        $user = UserFactory::createOne();
        $state = new NotificationTestState($this->connection);

        NotificationFactory::createOne(['recipient' => $user, 'createdAt' => $this->now->modify('-10 minutes'), 'toastPendingUntil' => $this->now->modify('-5 minutes')]);
        NotificationFactory::createOne(['recipient' => $user, 'createdAt' => $this->now]);
        NotificationFactory::createOne(['recipient' => $user, 'createdAt' => $this->now, 'toastPendingUntil' => $this->now->modify('+5 minutes'), 'inAppVisible' => false]);
        $state->markRead($this->toast($user, '2026-10-22 14:59:00'), $this->now);
        $state->markRemoved($this->toast($user, '2026-10-22 14:59:00'), $this->now);
        $this->toast(UserFactory::createOne(), '2026-10-22 14:59:00');

        self::assertSame([], $this->claimer->claim((int) $user->getId(), $this->now, 10));
    }

    public function testRespectsTheLimit(): void
    {
        $user = UserFactory::createOne();
        $first = $this->toast($user, '2026-10-22 14:57:00');
        $this->toast($user, '2026-10-22 14:58:00');

        $claimed = $this->claimer->claim((int) $user->getId(), $this->now, 1);

        self::assertCount(1, $claimed);
        self::assertSame($first->getId(), $claimed[0]->entry->id);
        self::assertCount(1, $this->claimer->claim((int) $user->getId(), $this->now, 1), 'the rest stays pending for the next poll');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function toast(User $user, string $createdAt, array $attributes = []): Notification
    {
        $created = new \DateTimeImmutable($createdAt);

        return NotificationFactory::createOne([
            'recipient' => $user,
            'createdAt' => $created,
            'toastPendingUntil' => $created->modify('+5 minutes'),
            ...$attributes,
        ]);
    }
}
