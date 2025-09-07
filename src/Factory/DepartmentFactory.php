<?php

namespace App\Factory;

use App\Entity\Department;
use Faker\Provider\MedicalSpecialities;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Department>
 */
final class DepartmentFactory extends PersistentProxyObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Department::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        /**
         * @see App\Faker\Provider\MedicalSpecialites
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
