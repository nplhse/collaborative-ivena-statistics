<?php

namespace App\Factory;

use App\Entity\Occasion;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Occasion>
 */
final class OccasionFactory extends PersistentProxyObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Occasion::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        /**
         * @see App\Faker\Provider\Occasion
         *
         * @var \Faker\Generator&\App\Faker\Provider\OccasionFakerMethods $faker
         */
        $faker = self::faker();

        return [
            'createdAt' => \DateTimeImmutable::createFromMutable($faker->dateTime()),
            'createdBy' => UserFactory::random(),
            'name' => $faker->occasion(),
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        self::faker()->addProvider(new \App\Faker\Provider\Occasion(self::faker()));

        return $this;
    }
}
