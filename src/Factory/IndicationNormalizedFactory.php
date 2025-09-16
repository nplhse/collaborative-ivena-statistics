<?php

namespace App\Factory;

use App\Entity\IndicationNormalized;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<IndicationNormalized>
 */
final class IndicationNormalizedFactory extends PersistentProxyObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return IndicationNormalized::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        /**
         * @see App\Faker\Provider\Indication
         *
         * @var \Faker\Generator&\App\Faker\Provider\IndicationFakerMethods $faker
         */
        $faker = self::faker();

        return [
            'createdAt' => \DateTimeImmutable::createFromMutable($faker->dateTime()),
            'createdBy' => UserFactory::randomOrCreate(),
            'code' => $faker->indicationCode(),
            'name' => $faker->indicationName(),
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        self::faker()->addProvider(new \App\Faker\Provider\Indication(self::faker()));

        return $this;
    }
}
