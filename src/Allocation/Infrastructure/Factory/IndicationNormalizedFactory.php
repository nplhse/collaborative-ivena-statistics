<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\IndicationNormalized;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<IndicationNormalized>
 */
final class IndicationNormalizedFactory extends PersistentObjectFactory
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
         * @var \Faker\Generator&\App\Allocation\Infrastructure\Faker\Provider\IndicationFakerMethods $faker
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
        self::faker()->addProvider(new \App\Allocation\Infrastructure\Faker\Provider\Indication(self::faker()));

        return $this;
    }
}
