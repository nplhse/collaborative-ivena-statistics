<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Factory;

use App\Content\Domain\Entity\Post;
use App\Content\Domain\Enum\PostStatus;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Post>
 */
final class PostFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Post::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        $title = self::faker()->unique()->sentence(4);

        return [
            'category' => PostCategoryFactory::new()->withoutAutorefresh()->randomOrCreate(),
            'content' => '<p>'.self::faker()->realText().'</p>',
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
            'createdBy' => UserFactory::new()->withoutAutorefresh()->randomOrCreate(),
            'publishedAt' => new \DateTimeImmutable('-1 day'),
            'slug' => strtolower(new AsciiSlugger()->slug($title)->toString()),
            'status' => PostStatus::PUBLISHED,
            'title' => $title,
            'tags' => PostTagFactory::new()->withoutAutorefresh()->range(1, 3),
        ];
    }
}
