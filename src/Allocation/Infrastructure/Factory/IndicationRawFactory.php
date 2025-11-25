<?php

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\IndicationRaw;
use App\Import\Infrastructure\Indication\IndicationKey;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<IndicationRaw>
 */
final class IndicationRawFactory extends PersistentProxyObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return IndicationRaw::class;
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

        $indicationCode = $faker->indicationCode();
        $indicationName = $faker->indicationName();

        return [
            'createdAt' => \DateTimeImmutable::createFromMutable($faker->dateTime()),
            'createdBy' => UserFactory::randomOrCreate(),
            'code' => $indicationCode,
            'name' => $indicationName,
            'hash' => IndicationKey::hashFrom($indicationCode, $indicationName),
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        self::faker()->addProvider(new \App\Allocation\Infrastructure\Faker\Provider\Indication(self::faker()));

        return $this;
    }
}
