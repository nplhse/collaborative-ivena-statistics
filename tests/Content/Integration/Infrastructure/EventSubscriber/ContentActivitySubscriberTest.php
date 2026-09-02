<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Infrastructure\EventSubscriber;

use App\Content\Application\Event\CommentCreated;
use App\Content\Application\Event\PostPublished;
use App\Content\Infrastructure\EventSubscriber\ContentActivitySubscriber;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Contract\UserActivityRecorderInterface;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\UserActivityRepository;

final class ContentActivitySubscriberTest extends DatabaseKernelTestCase
{
    public function testPublishAndCommentWriteActivityAndRetryIsDeduplicated(): void
    {
        $user = UserFactory::createOne();
        $userId = $user->getId();
        self::assertNotNull($userId);

        $subscriber = new ContentActivitySubscriber(
            self::getContainer()->get(UserActivityRecorderInterface::class),
        );
        $publishedAt = new \DateTimeImmutable('2026-07-12 10:00:00');
        $event = new PostPublished($userId, 88, 'Neue Möglichkeiten', 'neue-moeglichkeiten', $publishedAt);
        $subscriber->onPostPublished($event);
        $subscriber->onPostPublished($event);
        $subscriber->onCommentCreated(new CommentCreated(
            userId: $userId,
            commentId: 9,
            postTitle: 'Neue Möglichkeiten',
            postSlug: 'neue-moeglichkeiten',
            excerpt: 'Kurzer Ausschnitt',
            occurredAt: new \DateTimeImmutable('2026-07-18 11:00:00'),
        ));

        $rows = self::getContainer()->get(UserActivityRepository::class)->findBy(['user' => $user], ['id' => 'ASC']);
        self::assertCount(2, $rows);
        self::assertSame(UserActivityType::POST_PUBLISHED, $rows[0]->getType());
        self::assertSame('Neue Möglichkeiten', $rows[0]->getMetadata()['title'] ?? null);
        self::assertSame(UserActivityType::COMMENT_CREATED, $rows[1]->getType());
        self::assertSame('Kurzer Ausschnitt', $rows[1]->getMetadata()['excerpt'] ?? null);
    }

    public function testRescheduledPublishUpdatesOccurredAtWithoutDuplicate(): void
    {
        $user = UserFactory::createOne();
        $userId = $user->getId();
        self::assertNotNull($userId);

        $subscriber = new ContentActivitySubscriber(
            self::getContainer()->get(UserActivityRecorderInterface::class),
        );
        $subscriber->onPostPublished(new PostPublished(
            $userId,
            19,
            'Geplant',
            'geplant',
            new \DateTimeImmutable('2026-09-03 10:42:00'),
        ));
        $subscriber->onPostPublished(new PostPublished(
            $userId,
            19,
            'Die Entstehungsgeschichte des Projekts',
            'die-entstehungsgeschichte-des-projekts',
            new \DateTimeImmutable('2026-09-02 11:00:00'),
        ));

        $rows = self::getContainer()->get(UserActivityRepository::class)->findBy(['user' => $user], ['id' => 'ASC']);
        self::assertCount(1, $rows);
        self::assertSame(UserActivityType::POST_PUBLISHED, $rows[0]->getType());
        self::assertEquals(new \DateTimeImmutable('2026-09-02 11:00:00'), $rows[0]->getOccurredAt());
        self::assertSame('Die Entstehungsgeschichte des Projekts', $rows[0]->getMetadata()['title'] ?? null);
        self::assertSame('die-entstehungsgeschichte-des-projekts', $rows[0]->getMetadata()['slug'] ?? null);
    }
}
