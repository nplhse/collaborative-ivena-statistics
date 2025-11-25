<?php

namespace App\Tests\Seed\Unit\Provider;

use App\Allocation\Domain\Entity\Speciality;
use App\Seed\Infrastructure\Provider\SpecialitySeedProvider;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class SpecialitySeedProviderTest extends TestCase
{
    public function testBuildCreatesFirstEntityWithExpectedNameAndCreatedBy(): void
    {
        $provider = new SpecialitySeedProvider();

        $user = new User();

        /** @var array<int, object> $entities */
        $entities = \iterator_to_array($provider->build($user), false);

        self::assertNotEmpty($entities, 'build() should yield at least one entity');

        $first = $entities[0];

        self::assertInstanceOf(Speciality::class, $first);
        self::assertSame('Augenheilkunde', $first->getName());
        self::assertSame($user, $first->getCreatedBy());
    }

    public function testProvideReturnsExpectedSpecialitiesInOrder(): void
    {
        $provider = new SpecialitySeedProvider();
        $values = \iterator_to_array($provider->provide(), false);

        self::assertSame('Augenheilkunde', $values[0] ?? null);
        self::assertContains('Innere Medizin', $values);
        self::assertContains('Neurologie', $values);
        self::assertSame('Zentrale Notaufnahme', $values[\count($values) - 1] ?? null);

        self::assertSame($values, array_values(array_unique($values)));
        self::assertGreaterThanOrEqual(20, \count($values));
    }

    public function testGetTypeIsSpeciality(): void
    {
        self::assertSame('speciality', new SpecialitySeedProvider()->getType());
    }

    public function testPurgeTablesReturnSpeciality(): void
    {
        $provider = new SpecialitySeedProvider();

        self::assertSame(['speciality'], $provider->purgeTables());
    }
}
