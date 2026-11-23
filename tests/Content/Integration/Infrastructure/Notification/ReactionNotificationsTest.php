<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Infrastructure\Notification;

use App\Content\Application\Message\CleanUpReactionsMessage;
use App\Content\Application\Message\RefreshReactionNotificationMessage;
use App\Content\Application\Reaction\ReactionService;
use App\Content\Application\Reaction\ReactionTarget;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Domain\Enum\ReactionTargetType;
use App\Content\Domain\Enum\ReactionType;
use App\Content\Infrastructure\Factory\PostCommentFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Content\Infrastructure\Notification\ReactionReceivedNotification;
use App\Notification\Application\Contract\NotificationInboxWriterInterface;
use App\Notification\Application\Contract\NotificationPreferenceStoreInterface;
use App\Notification\Application\Preference\NotificationPreferenceChange;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The whole path from a reaction to its grouped entry; in the test environment the refresh job runs synchronously.
 */
final class ReactionNotificationsTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::mockTime('2026-11-23 12:00:00');
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testTwelveReactionsOfDifferentTypesAreOneEntry(): void
    {
        $owner = UserFactory::createOne();
        $target = $this->postOf($owner, 'Launch');

        foreach ([...array_fill(0, 7, ReactionType::Like), ...array_fill(0, 3, ReactionType::Love), ...array_fill(0, 2, ReactionType::Hooray)] as $type) {
            $this->react(UserFactory::createOne(), $target, $type);
        }

        $entries = $this->entries($owner);
        self::assertCount(1, $entries);
        self::assertSame(['targetType' => 'post', 'title' => 'Launch', 'count' => 12, 'breakdown' => '👍 7 ❤️ 3 🎉 2'], $entries[0]['parameters']);
        self::assertSame(1, $this->unreadCount($owner));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification_delivery'), 'never by email');
        self::assertNull($entries[0]['toast_pending_until'], 'never as a toast');
    }

    public function testDifferentTargetsStaySeparate(): void
    {
        $owner = UserFactory::createOne();
        $reactor = UserFactory::createOne();

        $this->react($reactor, $this->postOf($owner, 'First'), ReactionType::Like);
        $this->react($reactor, $this->postOf($owner, 'Second'), ReactionType::Like);

        self::assertCount(2, $this->entries($owner));
        self::assertSame(2, $this->unreadCount($owner));
    }

    public function testOwnReactionCreatesNothing(): void
    {
        $owner = UserFactory::createOne();

        $this->react($owner, $this->postOf($owner, 'Mine'), ReactionType::Like);

        self::assertSame([], $this->entries($owner));
    }

    public function testOwnReactionIsNotCounted(): void
    {
        $owner = UserFactory::createOne();
        $target = $this->postOf($owner, 'Mine');

        $this->react(UserFactory::createOne(), $target, ReactionType::Like);
        $this->react($owner, $target, ReactionType::Love);

        self::assertSame(1, $this->entries($owner)[0]['parameters']['count']);
    }

    public function testChangedReactionKeepsCountAndPosition(): void
    {
        $owner = UserFactory::createOne();
        $reactor = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->react($reactor, $target, ReactionType::Like);

        Clock::get()->sleep(3600);
        $this->react($reactor, $target, ReactionType::Rocket);

        $entry = $this->entries($owner)[0];
        self::assertSame(1, $entry['parameters']['count']);
        self::assertSame('🚀 1', $entry['parameters']['breakdown']);
        self::assertSame('2026-11-23 12:00:00', $entry['created_at']);
    }

    public function testAdditionalReactionMovesTheEntryUpWithoutRaisingTheUnreadCount(): void
    {
        $owner = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->react(UserFactory::createOne(), $target, ReactionType::Like);

        Clock::get()->sleep(3600);
        $this->react(UserFactory::createOne(), $target, ReactionType::Like);

        $entry = $this->entries($owner)[0];
        self::assertSame(2, $entry['parameters']['count']);
        self::assertSame('2026-11-23 13:00:00', $entry['created_at']);
        self::assertSame(1, $this->unreadCount($owner));
    }

    public function testRemovingTheLastReactionWithdrawsTheEntry(): void
    {
        $owner = UserFactory::createOne();
        $reactor = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->react($reactor, $target, ReactionType::Like);

        $this->react($reactor, $target, ReactionType::Like);

        self::assertSame([], $this->entries($owner));
        self::assertSame(0, $this->unreadCount($owner));
    }

    public function testAfterReadingOnlyNewReactionsAreReported(): void
    {
        $owner = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->react(UserFactory::createOne(), $target, ReactionType::Like);
        $this->react(UserFactory::createOne(), $target, ReactionType::Like);
        $this->markAllRead($owner);

        $this->react(UserFactory::createOne(), $target, ReactionType::Hooray);

        $entries = $this->entries($owner);
        self::assertCount(2, $entries);
        self::assertSame(2, $entries[0]['parameters']['count'], 'the read entry stays as it was');
        self::assertSame(['targetType' => 'post', 'title' => 'Post', 'count' => 1, 'breakdown' => '🎉 1'], $entries[1]['parameters']);
        self::assertSame(1, $this->unreadCount($owner));
    }

    public function testChangingAReactionFromAReadGroupReportsNothing(): void
    {
        $owner = UserFactory::createOne();
        $reactor = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->react($reactor, $target, ReactionType::Like);
        $this->markAllRead($owner);

        $this->react($reactor, $target, ReactionType::Rocket);

        self::assertCount(1, $this->entries($owner));
        self::assertSame(0, $this->unreadCount($owner));
    }

    public function testRemovingAReactionFromAReadGroupKeepsTheReadEntry(): void
    {
        $owner = UserFactory::createOne();
        $reactor = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->react($reactor, $target, ReactionType::Like);
        $this->markAllRead($owner);

        $this->react($reactor, $target, ReactionType::Like);

        $entries = $this->entries($owner);
        self::assertCount(1, $entries);
        self::assertSame(1, $entries[0]['parameters']['count']);
        self::assertSame(0, $this->unreadCount($owner));
    }

    public function testADeletedReadEntryDoesNotBringBackTheLifetimeTotal(): void
    {
        $owner = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->react(UserFactory::createOne(), $target, ReactionType::Like);
        $this->react(UserFactory::createOne(), $target, ReactionType::Like);
        $this->markAllRead($owner);
        $this->connection->executeStatement('DELETE FROM notification WHERE recipient_id = ?', [$owner->getId()]);

        $this->react(UserFactory::createOne(), $target, ReactionType::Like);

        self::assertSame(1, $this->entries($owner)[0]['parameters']['count']);
    }

    public function testRunningTheJobAgainChangesNothing(): void
    {
        $owner = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->react(UserFactory::createOne(), $target, ReactionType::Like);
        $before = $this->entries($owner);

        $this->refresh($target);
        $this->refresh($target);

        self::assertSame($before, $this->entries($owner));
        self::assertSame(1, $this->unreadCount($owner));
    }

    public function testReactionsWhileSwitchedOffAreNotReportedLater(): void
    {
        $owner = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->setPreference($owner, false);
        $this->react(UserFactory::createOne(), $target, ReactionType::Like);
        self::assertSame([], $this->entries($owner));

        $this->setPreference($owner, true);
        $this->refresh($target);
        self::assertSame([], $this->entries($owner));

        $this->react(UserFactory::createOne(), $target, ReactionType::Love);
        self::assertSame('❤️ 1', $this->entries($owner)[0]['parameters']['breakdown']);
    }

    public function testSwitchingOffStopsUpdatingAnOpenEntry(): void
    {
        $owner = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->react(UserFactory::createOne(), $target, ReactionType::Like);
        $this->setPreference($owner, false);

        $this->react(UserFactory::createOne(), $target, ReactionType::Love);

        self::assertSame(1, $this->entries($owner)[0]['parameters']['count']);
    }

    public function testReactionsOfDisabledUsersAreNotCounted(): void
    {
        $owner = UserFactory::createOne();
        $disabled = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->react($disabled, $target, ReactionType::Like);
        $this->react(UserFactory::createOne(), $target, ReactionType::Love);
        $this->connection->executeStatement('UPDATE "user" SET is_enabled = FALSE WHERE id = ?', [$disabled->getId()]);

        $this->refresh($target);

        self::assertSame('❤️ 1', $this->entries($owner)[0]['parameters']['breakdown']);
    }

    public function testADisabledOwnerGetsNoEntryAndAnOpenOneIsWithdrawn(): void
    {
        $owner = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->react(UserFactory::createOne(), $target, ReactionType::Like);
        $this->connection->executeStatement('UPDATE "user" SET is_enabled = FALSE WHERE id = ?', [$owner->getId()]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->refresh($target);

        self::assertSame([], $this->entries($owner));
    }

    public function testATargetTheOwnerCanNoLongerSeeWithdrawsTheEntry(): void
    {
        $owner = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->react(UserFactory::createOne(), $target, ReactionType::Like);
        $this->connection->executeStatement('UPDATE post SET status = ? WHERE id = ?', [PostStatus::DRAFT->value, $target->id]);

        $this->refresh($target);

        self::assertSame([], $this->entries($owner));
    }

    public function testCommentEntryNamesThePostAndNeverTheCommentText(): void
    {
        $owner = UserFactory::createOne();
        $post = PostFactory::createOne(['title' => 'Blog post', 'createdBy' => UserFactory::createOne()]);
        $comment = PostCommentFactory::createOne(['post' => $post, 'author' => $owner, 'content' => 'Secret text']);

        $this->react(UserFactory::createOne(), new ReactionTarget(ReactionTargetType::Comment, (int) $comment->getId()), ReactionType::Like);

        $entry = $this->entries($owner)[0];
        self::assertSame('comment', $entry['parameters']['targetType']);
        self::assertSame('Blog post', $entry['parameters']['title']);
        self::assertStringNotContainsString('Secret text', (string) json_encode($entry['parameters']));
    }

    public function testActivityNotifiesItsUserForEveryType(): void
    {
        $owner = UserFactory::createOne();
        $activity = new UserActivity($owner, UserActivityType::JOINED, new \DateTimeImmutable('2026-11-01'), 'joined-973');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($activity);
        $entityManager->flush();

        $this->react(UserFactory::createOne(), new ReactionTarget(ReactionTargetType::Activity, (int) $activity->getId()), ReactionType::Hooray);

        self::assertSame(['targetType' => 'activity', 'title' => '', 'count' => 1, 'breakdown' => '🎉 1'], $this->entries($owner)[0]['parameters']);
    }

    public function testReactionsFromBeforeTheReleaseAreNotReported(): void
    {
        $owner = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->setPreference($owner, false);
        $this->react(UserFactory::createOne(), $target, ReactionType::Like);
        $this->react(UserFactory::createOne(), $target, ReactionType::Like);
        $this->connection->executeStatement('DELETE FROM reaction_notification_state');
        $this->connection->executeStatement(
            'INSERT INTO reaction_notification_state (target_type, target_id, notification_id, floor_reaction_id, through_reaction_id)
             SELECT target_type, target_id, NULL, MAX(id), MAX(id) FROM reaction GROUP BY target_type, target_id',
        );
        $this->setPreference($owner, true);

        $this->react(UserFactory::createOne(), $target, ReactionType::Love);

        self::assertSame(1, $this->entries($owner)[0]['parameters']['count']);
    }

    public function testCleanupWithdrawsTheEntryOfADeletedTarget(): void
    {
        $owner = UserFactory::createOne();
        $target = $this->postOf($owner, 'Post');
        $this->react(UserFactory::createOne(), $target, ReactionType::Like);
        $this->connection->executeStatement('DELETE FROM post WHERE id = ?', [$target->id]);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new CleanUpReactionsMessage());

        self::assertSame([], $this->entries($owner));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reaction_notification_state'));
    }

    private function postOf(User $owner, string $title): ReactionTarget
    {
        $post = PostFactory::createOne(['title' => $title, 'createdBy' => $owner]);

        return new ReactionTarget(ReactionTargetType::Post, (int) $post->getId());
    }

    private function react(User $user, ReactionTarget $target, ReactionType $type): void
    {
        self::getContainer()->get(ReactionService::class)->toggle($user, $target, $type);
    }

    private function refresh(ReactionTarget $target): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new RefreshReactionNotificationMessage($target->type, $target->id));
    }

    private function markAllRead(User $user): void
    {
        self::getContainer()->get(NotificationInboxWriterInterface::class)->markAllRead((int) $user->getId());
    }

    private function setPreference(User $user, bool $enabled): void
    {
        self::getContainer()->get(NotificationPreferenceStoreInterface::class)->save((int) $user->getId(), [
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, ReactionReceivedNotification::TYPE, NotificationChannel::InApp, $enabled),
        ]);
    }

    /**
     * Entries in the inbox, oldest first.
     *
     * @return list<array{parameters: array<string, mixed>, created_at: string, toast_pending_until: ?string}>
     */
    private function entries(User $user): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT parameters, created_at, toast_pending_until FROM notification
             WHERE recipient_id = ? AND type = ? AND removed_at IS NULL ORDER BY id',
            [$user->getId(), ReactionReceivedNotification::TYPE],
        );

        return array_map(static function (array $row): array {
            $parameters = json_decode((string) $row['parameters'], true);
            self::assertIsArray($parameters);

            return [
                'parameters' => $parameters,
                'created_at' => (string) $row['created_at'],
                'toast_pending_until' => null === $row['toast_pending_until'] ? null : (string) $row['toast_pending_until'],
            ];
        }, $rows);
    }

    private function unreadCount(User $user): int
    {
        return (int) $this->connection->fetchOne('SELECT unread_count FROM notification_recipient_state WHERE user_id = ?', [$user->getId()]);
    }
}
