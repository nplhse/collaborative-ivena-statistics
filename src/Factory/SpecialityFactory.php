<?php

namespace App\Factory;

use App\Entity\Speciality;
use App\Faker\Provider\MedicalSpecialities;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Speciality>
 */
final class SpecialityFactory extends PersistentProxyObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Speciality::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        /**
         * @see App\Faker\Provider\MedicalSpecialities
         *
         * @var \Faker\Generator&\App\Faker\Provider\MedicalSpecialityFakerMethods $faker
         */
        $faker = self::faker();

        return [
            'createdAt' => \DateTimeImmutable::createFromMutable($faker->dateTime()),
            'createdBy' => UserFactory::random(),
            'name' => $faker->medicalDepartment(),
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        self::faker()->addProvider(new MedicalSpecialities(self::faker()));

        return $this;
    }
}
