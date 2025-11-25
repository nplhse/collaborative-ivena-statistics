<?php

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\Infection;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Infection>
 */
final class InfectionFactory extends PersistentProxyObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Infection::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        /**
         * @see App\Faker\Provider\Infection
         *
         * @var \Faker\Generator&\App\Allocation\Infrastructure\Faker\Provider\InfectionFakerMethods $faker
         */
        $faker = self::faker();

        return [
            'createdAt' => \DateTimeImmutable::createFromMutable($faker->dateTime()),
            'createdBy' => UserFactory::randomOrCreate(),
            'name' => $faker->infection(),
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        self::faker()->addProvider(new \App\Allocation\Infrastructure\Faker\Provider\Infection(self::faker()));

        return $this;
    }
}
