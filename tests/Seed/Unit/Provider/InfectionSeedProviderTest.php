<?php

namespace App\Tests\Seed\Unit\Provider;

use App\Allocation\Domain\Entity\Infection;
use App\Seed\Infrastructure\Provider\InfectionSeedProvider;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class InfectionSeedProviderTest extends TestCase
{
    public function testBuildCreatesFirstEntityWithExpectedNameAndCreatedBy(): void
    {
        $provider = new InfectionSeedProvider();

        $user = new User();

        /** @var array<int, object> $entities */
        $entities = \iterator_to_array($provider->build($user), false);

        self::assertNotEmpty($entities, 'build() should yield at least one entity');

        $first = $entities[0];

        self::assertInstanceOf(Infection::class, $first);
        self::assertSame('3MRGN', $first->getName());
        self::assertSame($user, $first->getCreatedBy());
    }

    public function testProvideReturnsExpectedInfectionsInOrder(): void
    {
        $provider = new InfectionSeedProvider();
        $values = \iterator_to_array($provider->provide(), false);

        self::assertSame('3MRGN', $values[0] ?? null);
        self::assertSame('Varizellen', $values[\count($values) - 1] ?? null);

        self::assertContains('MRSA', $values);
        self::assertContains('Influenza', $values);
        self::assertContains('V.a. COVID', $values);

        self::assertSame($values, array_values(array_unique($values)));

        self::assertCount(19, $values);
    }

    public function testGetTypeIsInfection(): void
    {
        self::assertSame('infection', new InfectionSeedProvider()->getType());
    }

    public function testPurgeTablesReturnInfection(): void
    {
        $provider = new InfectionSeedProvider();

        self::assertSame(['infection'], $provider->purgeTables());
    }
}
