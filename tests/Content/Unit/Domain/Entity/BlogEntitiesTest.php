<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Domain\Entity;

use App\Content\Domain\Entity\Post;
use App\Content\Domain\Entity\PostCategory;
use App\Content\Domain\Entity\PostComment;
use App\Content\Domain\Entity\PostTag;
use App\Content\Domain\Enum\PostStatus;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class BlogEntitiesTest extends TestCase
{
    public function testPostEntityBehavior(): void
    {
        $post = new Post();
        $category = new PostCategory()->setName('News')->setSlug('news');
        $tag = new PostTag()->setName('Release')->setSlug('release');

        $post
            ->setTitle('My First Post')
            ->setContent('<p>Hello</p>')
            ->setStatus(PostStatus::PUBLISHED)
            ->setCategory($category)
            ->setPublishedAt(new \DateTimeImmutable('-1 hour'))
        ;

        $post->addTag($tag);
        self::assertCount(1, $post->getTags());
        self::assertNull($post->getSlug());

        $post->removeTag($tag);
        self::assertCount(0, $post->getTags());

        $post->setUpdatedAt(null);
        $post->updateTimestamps();
        self::assertNotNull($post->getUpdatedAt());
        self::assertSame('My First Post', (string) $post);
    }

    public function testPostCategoryAndTagStringAndTimestamps(): void
    {
        $category = new PostCategory()->setName('Updates')->setSlug('updates');
        $tag = new PostTag()->setName('Research')->setSlug('research');

        self::assertSame('Updates', (string) $category);
        self::assertSame('Research', (string) $tag);

        $category->setUpdatedAt(null);
        $tag->setUpdatedAt(null);
        $category->updateTimestamps();
        $tag->updateTimestamps();

        self::assertNotNull($category->getUpdatedAt());
        self::assertNotNull($tag->getUpdatedAt());
    }

    public function testPostCommentBehavior(): void
    {
        $author = new User()
            ->setUsername('comment-author')
            ->setEmail('author@example.com')
            ->setPassword('secret')
        ;

        $post = new Post()->setTitle('Commented');
        $parent = new PostComment()->setContent('Parent')->setAuthor($author)->setPost($post);
        $child = new PostComment()->setContent('Child')->setAuthor($author)->setPost($post)->setParent($parent);

        self::assertSame($post, $child->getPost());
        self::assertSame($parent, $child->getParent());
        self::assertSame('Child', $child->getContent());

        $child->setUpdatedAt(null);
        $child->updateTimestamps();
        self::assertNotNull($child->getUpdatedAt());
    }
}
