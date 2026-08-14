<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Application\Blog;

use App\Content\Application\Blog\ContentActivityNotifier;
use App\Content\Application\Event\CommentCreated;
use App\Content\Application\Event\PostPublished;
use App\Content\Domain\Entity\Post;
use App\Content\Domain\Entity\PostComment;
use App\Content\Domain\Enum\PostStatus;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ContentActivityNotifierTest extends TestCase
{
    public function testFirstPublishDispatchesEvent(): void
    {
        $post = $this->post(PostStatus::PUBLISHED);
        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(PostPublished::class, static function (object $event) use (&$events): void {
            $events[] = $event;
        });

        new ContentActivityNotifier($dispatcher)->postPublishedIfApplicable($post, PostStatus::DRAFT);

        self::assertCount(1, $events);
        self::assertInstanceOf(PostPublished::class, $events[0]);
        self::assertSame(8, $events[0]->postId);
        self::assertSame('Titel', $events[0]->title);
    }

    public function testAlreadyPublishedEditDoesNotDispatch(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        new ContentActivityNotifier($dispatcher)->postPublishedIfApplicable(
            $this->post(PostStatus::PUBLISHED),
            PostStatus::PUBLISHED,
        );
    }

    public function testDraftDoesNotDispatch(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        new ContentActivityNotifier($dispatcher)->postPublishedIfApplicable(
            $this->post(PostStatus::DRAFT),
            null,
        );
    }

    public function testInsertAsPublishedDispatchesEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(PostPublished::class, static function (object $event) use (&$events): void {
            $events[] = $event;
        });

        new ContentActivityNotifier($dispatcher)->postPublishedIfApplicable(
            $this->post(PostStatus::PUBLISHED),
            null,
        );

        self::assertCount(1, $events);
    }

    public function testCommentCreatedDispatchesExcerpt(): void
    {
        $post = $this->post(PostStatus::PUBLISHED);
        $comment = new PostComment();
        $comment->setPost($post);
        $comment->setAuthor($post->getCreatedBy() ?? new User());
        $comment->setContent('<p>Hallo Welt</p>');
        $commentId = new \ReflectionProperty(PostComment::class, 'id');
        $commentId->setValue($comment, 9);

        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(CommentCreated::class, static function (object $event) use (&$events): void {
            $events[] = $event;
        });

        new ContentActivityNotifier($dispatcher)->commentCreated($comment);

        self::assertCount(1, $events);
        self::assertInstanceOf(CommentCreated::class, $events[0]);
        self::assertSame(9, $events[0]->commentId);
        self::assertSame('Hallo Welt', $events[0]->excerpt);
        self::assertSame('titel', $events[0]->postSlug);
    }

    private function post(PostStatus $status): Post
    {
        $user = new User();
        $userId = new \ReflectionProperty(User::class, 'id');
        $userId->setValue($user, 4);

        $post = new Post();
        $post->setTitle('Titel');
        $post->setSlug('titel');
        $post->setStatus($status);
        $post->setPublishedAt(new \DateTimeImmutable('2026-07-12 10:00:00'));
        $post->setCreatedBy($user);
        $postId = new \ReflectionProperty(Post::class, 'id');
        $postId->setValue($post, 8);

        return $post;
    }
}
