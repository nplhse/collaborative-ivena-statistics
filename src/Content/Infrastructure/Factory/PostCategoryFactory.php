<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Factory;

use App\Content\Domain\Entity\PostCategory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<PostCategory>
 */
final class PostCategoryFactory extends PersistentProxyObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return PostCategory::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        $name = self::faker()->unique()->words(2, true);
        $name = \is_array($name) ? implode(' ', $name) : $name;

        return [
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
            'createdBy' => UserFactory::new(),
            'name' => $name,
            'slug' => strtolower(new AsciiSlugger()->slug($name)->toString()),
        ];
    }
}
