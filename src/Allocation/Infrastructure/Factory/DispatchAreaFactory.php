<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Infrastructure\Faker\Provider\Area;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<DispatchArea>
 */
final class DispatchAreaFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return DispatchArea::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        return [
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
            'createdBy' => UserFactory::new()->withoutAutorefresh()->randomOrCreate(),
            'updatedAt' => self::faker()->boolean(40)
                ? \DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-6 months', 'now'))
                : null,
            'name' => Area::dispatchArea(),
            'state' => StateFactory::new()->withoutAutorefresh()->randomOrCreate(),
        ];
    }
}
