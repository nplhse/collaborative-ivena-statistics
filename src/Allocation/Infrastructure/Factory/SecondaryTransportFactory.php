<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Infrastructure\Faker\Provider\SecondaryTransportFakerMethods;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<SecondaryTransport>
 */
final class SecondaryTransportFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return SecondaryTransport::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        /** @var \Faker\Generator&SecondaryTransportFakerMethods $faker */
        $faker = self::faker();

        return [
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime()),
            'createdBy' => LazyValue::new(static fn (): object => UserFactory::random()),
            'name' => $faker->secondaryTransport(),
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        self::faker()->addProvider(new \App\Allocation\Infrastructure\Faker\Provider\SecondaryTransport(self::faker()));

        return $this;
    }
}
