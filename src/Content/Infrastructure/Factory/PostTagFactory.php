<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Factory;

use App\Content\Domain\Entity\PostTag;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<PostTag>
 */
final class PostTagFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return PostTag::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        $name = self::faker()->unique()->word();

        return [
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
            'createdBy' => UserFactory::new()->withoutAutorefresh(),
            'name' => ucfirst($name),
            'slug' => strtolower(new AsciiSlugger()->slug($name)->toString()),
        ];
    }
}
