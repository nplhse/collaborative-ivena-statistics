<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Notification;

use App\Content\Application\Message\AnnouncePostMessage;
use App\Content\Domain\Entity\Post;
use App\Content\Infrastructure\Repository\PostRepository;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Publishes an announced blog post (`announcement.post`, #1486) to every enabled user. The deduplication key is the
 * post, so a retried message creates no second notification.
 *
 * @psalm-suppress UnusedClass Registered as a message handler.
 */
#[AsMessageHandler]
final readonly class AnnouncePostMessageHandler
{
    public function __construct(
        private PostRepository $posts,
        private UserRepository $users,
        private NotificationPublisherInterface $publisher,
    ) {
    }

    public function __invoke(AnnouncePostMessage $message): void
    {
        $post = $this->posts->find($message->postId);
        $slug = $post instanceof Post ? $post->getSlug() : null;
        if (!$post instanceof Post || null === $slug) {
            return;
        }

        $recipientIds = $this->users->findEnabledUserIds();
        if ([] === $recipientIds) {
            return;
        }

        $this->publisher->publish(new NotificationRequest(
            type: PostAnnouncementNotification::TYPE,
            recipientUserIds: $recipientIds,
            deduplicationKey: \sprintf('%s:%d', PostAnnouncementNotification::TYPE, $message->postId),
            parameters: ['title' => (string) $post->getTitle()],
            action: new NotificationAction('app_blog_show', ['slug' => $slug]),
            metadata: ['reference' => 'post:'.$message->postId],
        ));
    }
}
