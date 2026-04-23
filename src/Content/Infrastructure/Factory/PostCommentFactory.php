<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Factory;

use App\Content\Domain\Entity\PostComment;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<PostComment>
 */
final class PostCommentFactory extends PersistentProxyObjectFactory
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
            'author' => UserFactory::new(),
            'content' => self::faker()->paragraph(5),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
            'createdBy' => UserFactory::new(),
            'post' => PostFactory::random(),
        ];
    }
}
