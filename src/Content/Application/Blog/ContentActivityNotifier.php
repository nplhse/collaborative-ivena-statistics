<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

use App\Content\Application\Event\CommentCreated;
use App\Content\Application\Event\PostPublished;
use App\Content\Domain\Entity\Post;
use App\Content\Domain\Entity\PostComment;
use App\Content\Domain\Enum\PostStatus;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class ContentActivityNotifier
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by Symfony DI. */
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function postPublishedIfApplicable(
        Post $post,
        ?PostStatus $previousStatus,
        ?\DateTimeImmutable $previousPublishedAt = null,
    ): void {
        if (PostStatus::PUBLISHED !== $post->getStatus()) {
            return;
        }

        $postId = $post->getId();
        $userId = $post->getCreatedBy()?->getId();
        $title = $post->getTitle();
        $slug = $post->getSlug();
        $publishedAt = $post->getPublishedAt();
        if (!\is_int($postId) || !\is_int($userId) || !\is_string($title) || '' === $title || !\is_string($slug) || '' === $slug || !$publishedAt instanceof \DateTimeImmutable) {
            return;
        }

        if (PostStatus::PUBLISHED === $previousStatus && !$this->publicationTimeChanged($previousPublishedAt, $publishedAt)) {
            return;
        }

        $this->eventDispatcher->dispatch(new PostPublished(
            userId: $userId,
            postId: $postId,
            title: $title,
            slug: $slug,
            publishedAt: $publishedAt,
        ));
    }

    public function commentCreated(PostComment $comment): void
    {
        $commentId = $comment->getId();
        $userId = $comment->getAuthor()?->getId();
        $post = $comment->getPost();
        $postTitle = $post?->getTitle();
        $postSlug = $post?->getSlug();
        $content = $comment->getContent();
        if (!\is_int($commentId) || !\is_int($userId) || !\is_string($postTitle) || '' === $postTitle || !\is_string($postSlug) || '' === $postSlug || !\is_string($content)) {
            return;
        }

        $this->eventDispatcher->dispatch(new CommentCreated(
            userId: $userId,
            commentId: $commentId,
            postTitle: $postTitle,
            postSlug: $postSlug,
            excerpt: CommentExcerpt::from($content),
            occurredAt: $comment->getCreatedAt(),
        ));
    }

    private function publicationTimeChanged(?\DateTimeImmutable $previousPublishedAt, \DateTimeImmutable $publishedAt): bool
    {
        if (!$previousPublishedAt instanceof \DateTimeImmutable) {
            return true;
        }

        return $previousPublishedAt != $publishedAt;
    }
}
