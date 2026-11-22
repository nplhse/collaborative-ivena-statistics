<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Infrastructure\Notification;

use App\Content\Application\Message\AnnouncePostMessage;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Content\Infrastructure\Notification\AnnouncePostMessageHandler;
use App\Content\Infrastructure\Notification\PostAnnouncementNotification;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The background part of an announcement (#1486): nothing is published for a post that is gone or when no user is
 * enabled.
 */
#[ResetDatabase]
final class AnnouncePostMessageHandlerTest extends KernelTestCase
{
    use Factories;

    public function testAPostThatIsGonePublishesNothing(): void
    {
        self::bootKernel();

        $this->handler()(new AnnouncePostMessage(999999));

        self::assertSame(0, $this->announcementCount());
    }

    public function testNoEnabledUserPublishesNothing(): void
    {
        self::bootKernel();
        $post = PostFactory::createOne([
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('2020-01-01 09:00:00'),
        ]);
        $this->connection()->executeStatement('UPDATE "user" SET is_enabled = false');

        $this->handler()(new AnnouncePostMessage((int) $post->getId()));

        self::assertSame(0, $this->announcementCount());
    }

    private function handler(): AnnouncePostMessageHandler
    {
        return self::getContainer()->get(AnnouncePostMessageHandler::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function announcementCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification WHERE type = ?', [PostAnnouncementNotification::TYPE]);
    }
}
