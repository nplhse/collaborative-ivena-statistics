<?php

namespace App\Tests\Seed\Unit\Provider;

use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Seed\Infrastructure\Provider\SecondaryTransportSeedProvider;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class SecondaryTransportSeedProviderTest extends TestCase
{
    public function testBuildCreatesFirstEntityWithExpectedNameAndCreatedBy(): void
    {
        $provider = new SecondaryTransportSeedProvider();

        $user = new User();

        /** @var array<int, object> $entities */
        $entities = \iterator_to_array($provider->build($user), false);

        self::assertNotEmpty($entities, 'build() should yield at least one entity');

        $first = $entities[0];

        self::assertInstanceOf(SecondaryTransport::class, $first);
        self::assertSame('Diagnostik', $first->getName());
        self::assertSame($user, $first->getCreatedBy());
    }

    public function testProvideReturnsExpectedSecondaryTransportsInOrder(): void
    {
        $provider = new SecondaryTransportSeedProvider();
        $values = \iterator_to_array($provider->provide(), false);

        self::assertSame('Diagnostik', $values[0] ?? null);
        self::assertSame('Weaning', $values[\count($values) - 1] ?? null);

        self::assertContains('OP', $values);
        self::assertContains('Sekundärverlegung', $values);
        self::assertSame($values, array_values(array_unique($values)));

        self::assertCount(7, $values);
    }

    public function testGetTypeIsSecondaryTransport(): void
    {
        self::assertSame('secondary_transport', new SecondaryTransportSeedProvider()->getType());
    }

    public function testPurgeTablesReturnSecondaryTransport(): void
    {
        $provider = new SecondaryTransportSeedProvider();

        self::assertSame(['secondary_transport'], $provider->purgeTables());
    }
}
