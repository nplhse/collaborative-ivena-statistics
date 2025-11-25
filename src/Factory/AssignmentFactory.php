<?php

namespace App\Factory;

use App\Entity\Assignment;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Assignment>
 */
final class AssignmentFactory extends PersistentProxyObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Assignment::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        /**
         * @see App\Faker\Provider\Assignment
         *
         * @var \Faker\Generator&\App\Faker\Provider\AssignmentFakerMethods $faker
         */
        $faker = self::faker();

        return [
            'createdAt' => \DateTimeImmutable::createFromMutable($faker->dateTime()),
            'createdBy' => UserFactory::random(),
            'name' => $faker->assignment(),
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        self::faker()->addProvider(new \App\Faker\Provider\Assignment(self::faker()));

        return $this;
    }
}
