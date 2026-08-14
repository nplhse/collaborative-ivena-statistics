<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\EventSubscriber;

use App\Content\Application\Event\CommentCreated;
use App\Content\Application\Event\PostPublished;
use App\User\Application\Activity\UserActivityDeduplicationKey;
use App\User\Application\Activity\UserActivityWrite;
use App\User\Application\Contract\UserActivityRecorderInterface;
use App\User\Domain\Enum\UserActivityType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class ContentActivitySubscriber
{
    public function __construct(
        private UserActivityRecorderInterface $activityRecorder,
    ) {
    }

    #[AsEventListener(event: PostPublished::class)]
    public function onPostPublished(PostPublished $event): void
    {
        $this->activityRecorder->record(new UserActivityWrite(
            userId: $event->userId,
            type: UserActivityType::POST_PUBLISHED,
            occurredAt: $event->publishedAt,
            deduplicationKey: UserActivityDeduplicationKey::postPublished($event->userId, $event->postId),
            metadata: [
                'postId' => $event->postId,
                'title' => $event->title,
                'slug' => $event->slug,
            ],
        ));
    }

    #[AsEventListener(event: CommentCreated::class)]
    public function onCommentCreated(CommentCreated $event): void
    {
        $this->activityRecorder->record(new UserActivityWrite(
            userId: $event->userId,
            type: UserActivityType::COMMENT_CREATED,
            occurredAt: $event->occurredAt,
            deduplicationKey: UserActivityDeduplicationKey::commentCreated($event->userId, $event->commentId),
            metadata: [
                'commentId' => $event->commentId,
                'postTitle' => $event->postTitle,
                'postSlug' => $event->postSlug,
                'excerpt' => $event->excerpt,
            ],
        ));
    }
}
