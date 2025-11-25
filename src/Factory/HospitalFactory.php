<?php

namespace App\Factory;

use App\Entity\Hospital;
use App\Enum\HospitalLocation;
use App\Enum\HospitalSize;
use App\Enum\HospitalTier;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Hospital>
 */
final class HospitalFactory extends PersistentProxyObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Hospital::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        /**
         * @see App\Faker\Provider\Hospital
         *
         * @var \Faker\Generator&\App\Faker\Provider\HospitalFakerMethods $faker
         */
        $faker = self::faker();

        return [
            'address' => AddressFactory::new()->create(),
            'beds' => self::faker()->numberBetween(100, 1250),
            'owner' => UserFactory::random(),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
            'createdBy' => UserFactory::random(),
            'dispatchArea' => DispatchAreaFactory::random(),
            'location' => self::faker()->randomElement(HospitalLocation::cases()),
            'name' => $faker->hospital(),
            'size' => self::faker()->randomElement(HospitalSize::cases()),
            'state' => StateFactory::random(),
            'tier' => self::faker()->randomElement(HospitalTier::cases()),
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        self::faker()->addProvider(new \App\Faker\Provider\Hospital(self::faker()));

        return $this;
    }
}
