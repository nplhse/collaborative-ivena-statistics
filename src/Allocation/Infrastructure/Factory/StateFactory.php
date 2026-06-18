<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\State;
use App\User\Domain\Factory\UserFactory;
use Faker\Provider\de_DE\Address;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<State>
 */
final class StateFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return State::class;
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
            'name' => Address::state(),
        ];
    }
}
