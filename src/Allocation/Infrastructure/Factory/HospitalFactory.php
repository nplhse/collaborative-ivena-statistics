<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Faker\Provider\Area;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Hospital>
 */
final class HospitalFactory extends PersistentObjectFactory
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
         * @var \Faker\Generator&\App\Allocation\Infrastructure\Faker\Provider\HospitalFakerMethods $faker
         */
        $faker = self::faker();

        // A consistent synthetic hospital: size, beds, tier, location, address and coordinates fit together.
        $profile = $faker->hospitalProfile(Area::dispatchArea());

        return [
            'address' => AddressFactory::new([
                'street' => $profile['address']['street'],
                'postalCode' => $profile['address']['postal_code'],
                'city' => $profile['address']['city'],
                'state' => $profile['address']['state'],
                'country' => $profile['address']['country'],
            ])->create(),
            'beds' => $profile['beds'],
            'owner' => UserFactory::new()->withoutAutorefresh()->randomOrCreate(),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
            'createdBy' => UserFactory::new()->withoutAutorefresh()->randomOrCreate(),
            'dispatchArea' => DispatchAreaFactory::new()->withoutAutorefresh()->randomOrCreate(),
            'latitude' => $profile['coordinates']['lat'],
            'location' => HospitalLocation::from($profile['location']),
            'longitude' => $profile['coordinates']['lng'],
            'name' => $profile['name'],
            'size' => HospitalSize::from($profile['size']),
            'state' => StateFactory::new()->withoutAutorefresh()->randomOrCreate(),
            'tier' => HospitalTier::from($profile['tier']),
        ];
    }

    /** @psalm-suppress MoreSpecificReturnType */
    #[\Override]
    protected function initialize(): static
    {
        self::faker()->addProvider(new \App\Allocation\Infrastructure\Faker\Provider\Hospital(self::faker()));

        /** @var static $factory */
        $factory = $this->afterInstantiate(
            /**
             * @param array<string, mixed> $attributes
             */
            static function (Hospital $hospital, array $attributes): void {
                if (!$hospital->isParticipating() || \array_key_exists('participatingSince', $attributes)) {
                    return;
                }

                $hospital->setParticipatingSince($hospital->getCreatedAt());
            },
        );

        return $factory;
    }
}
