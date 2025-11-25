<?php

namespace App\Factory;

use App\Entity\DispatchArea;
use App\Faker\Provider\Area;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<DispatchArea>
 */
final class DispatchAreaFactory extends PersistentProxyObjectFactory
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
            'createdBy' => UserFactory::random(),
            'updatedAt' => self::faker()->boolean(40)
                ? \DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-6 months', 'now'))
                : null,
            'name' => Area::dispatchArea(),
            'state' => StateFactory::random(),
        ];
    }
}
