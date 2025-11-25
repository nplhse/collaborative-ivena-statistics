<?php

namespace App\Tests\Unit\Service\Seed;

use App\Entity\Occasion;
use App\Service\Seed\OccasionSeedProvider;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class OccasionSeedProviderTest extends TestCase
{
    public function testBuildCreatesFirstEntityWithExpectedNameAndCreatedBy(): void
    {
        $provider = new OccasionSeedProvider();

        $user = new User();

        /** @var array<int, object> $entities */
        $entities = \iterator_to_array($provider->build($user), false);

        self::assertNotEmpty($entities, 'build() should yield at least one entity');

        $first = $entities[0];

        self::assertInstanceOf(Occasion::class, $first);
        self::assertSame('Arbeitsunfall', $first->getName());
        self::assertSame($user, $first->getCreatedBy());
    }

    public function testProvideReturnsExpectedOccasionsInOrder(): void
    {
        $provider = new OccasionSeedProvider();
        $values = \iterator_to_array($provider->provide(), false);

        self::assertSame('Arbeitsunfall', $values[0] ?? null);
        self::assertSame('Weaning', $values[\count($values) - 1] ?? null);

        self::assertContains('Verkehrsunfall', $values);
        self::assertContains('Sturz < 3m Höhe', $values);
        self::assertContains('Hausunfall', $values);

        self::assertSame($values, array_values(array_unique($values)));

        self::assertCount(29, $values);
    }

    public function testGetTypeIsOccasion(): void
    {
        self::assertSame('occasion', new OccasionSeedProvider()->getType());
    }

    public function testPurgeTablesReturnOccasion(): void
    {
        $provider = new OccasionSeedProvider();

        self::assertSame(['occasion'], $provider->purgeTables());
    }
}
