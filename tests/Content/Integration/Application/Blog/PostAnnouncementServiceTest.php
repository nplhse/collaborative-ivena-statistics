<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Application\Blog;

use App\Content\Application\Blog\PostAnnouncementService;
use App\Content\Domain\Entity\Post;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Domain\Exception\PostCannotBeAnnouncedException;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Content\Infrastructure\Notification\PostAnnouncementNotification;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Announcing a blog post (#1486): every enabled user gets one notification, on by default on every channel; a user
 * who switched the email off gets none by email. Messages run synchronously in the test environment.
 */
#[ResetDatabase]
final class PostAnnouncementServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::mockTime('2026-11-22 12:00:00');
        self::bootKernel();
    }

    public function testEveryEnabledUserGetsTheAnnouncementOnce(): void
    {
        $post = $this->publishedPost();
        $disabled = UserFactory::createOne(['isEnabled' => false]);
        $enabledIds = $this->enabledUserIds();

        $this->service()->announce($post);

        self::assertEquals(new \DateTimeImmutable('2026-11-22 12:00:00'), $post->getAnnouncedAt());
        self::assertSame($enabledIds, $this->recipientIds());
        self::assertNotContains($disabled->getId(), $this->recipientIds());
        self::assertSame(
            ['title' => 'Version 0.3 is here'],
            json_decode((string) $this->connection()->fetchOne('SELECT parameters FROM notification WHERE type = ? LIMIT 1', [PostAnnouncementNotification::TYPE]), true),
        );
    }

    public function testEmailIsOnByDefaultAndFollowsTheUsersSetting(): void
    {
        $post = $this->publishedPost();
        $withEmail = UserFactory::createOne();
        $withoutEmail = UserFactory::createOne();
        $this->connection()->insert('user_notification_preference', [
            'user_id' => $withoutEmail->getId(),
            'scope' => 'type',
            'preference_key' => PostAnnouncementNotification::TYPE,
            'channel' => 'email',
            'enabled' => false,
        ], ['enabled' => \Doctrine\DBAL\ParameterType::BOOLEAN]);

        $this->service()->announce($post);

        self::assertSame(1, $this->emailDeliveries((int) $withEmail->getId()));
        self::assertSame(0, $this->emailDeliveries((int) $withoutEmail->getId()));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification WHERE type = ? AND recipient_id = ? AND in_app_visible = true', [PostAnnouncementNotification::TYPE, $withoutEmail->getId()]));
    }

    public function testAPostIsAnnouncedOnlyOnce(): void
    {
        $post = $this->publishedPost();
        $this->service()->announce($post);
        $count = \count($this->recipientIds());

        try {
            $this->service()->announce($post);
            self::fail('A second announcement must be refused.');
        } catch (PostCannotBeAnnouncedException) {
        }

        self::assertCount($count, $this->recipientIds());
    }

    public function testDraftsAndFuturePostsCannotBeAnnounced(): void
    {
        foreach ([
            PostFactory::createOne(['status' => PostStatus::DRAFT, 'publishedAt' => new \DateTimeImmutable('2026-11-20 09:00:00')]),
            PostFactory::createOne(['status' => PostStatus::PUBLISHED, 'publishedAt' => new \DateTimeImmutable('2026-11-23 09:00:00')]),
        ] as $post) {
            try {
                $this->service()->announce($post);
                self::fail('Only a visible published post can be announced.');
            } catch (PostCannotBeAnnouncedException) {
            }

            self::assertNull($post->getAnnouncedAt());
        }

        self::assertSame([], $this->recipientIds());
    }

    private function publishedPost(): Post
    {
        return PostFactory::createOne([
            'title' => 'Version 0.3 is here',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('2026-11-22 08:00:00'),
        ]);
    }

    private function service(): PostAnnouncementService
    {
        return self::getContainer()->get(PostAnnouncementService::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    /**
     * @return list<int>
     */
    private function enabledUserIds(): array
    {
        return array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT id FROM "user" WHERE is_enabled = true ORDER BY id'));
    }

    /**
     * @return list<int>
     */
    private function recipientIds(): array
    {
        return array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT recipient_id FROM notification WHERE type = ? ORDER BY recipient_id', [PostAnnouncementNotification::TYPE]));
    }

    private function emailDeliveries(int $userId): int
    {
        return (int) $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM notification_delivery d JOIN notification n ON n.id = d.notification_id WHERE n.type = ? AND n.recipient_id = ? AND d.channel = 'email'",
            [PostAnnouncementNotification::TYPE, $userId],
        );
    }
}
