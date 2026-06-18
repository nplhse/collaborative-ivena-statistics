<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Factory;

use App\Content\Domain\Entity\PostComment;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<PostComment>
 */
final class PostCommentFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return PostComment::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        return [
            'author' => UserFactory::new()->withoutAutorefresh(),
            'content' => self::faker()->paragraph(5),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
            'createdBy' => UserFactory::new()->withoutAutorefresh(),
            'post' => PostFactory::new()->withoutAutorefresh()->random(),
        ];
    }
}
