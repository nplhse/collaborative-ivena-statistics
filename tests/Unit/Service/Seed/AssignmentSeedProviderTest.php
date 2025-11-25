<?php

namespace App\Tests\Unit\Service\Seed;

use App\Allocation\Domain\Entity\Assignment;
use App\Service\Seed\AssignmentSeedProvider;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class AssignmentSeedProviderTest extends TestCase
{
    public function testBuildCreatesFirstEntityWithExpectedNameAndCreatedBy(): void
    {
        $provider = new AssignmentSeedProvider();

        $user = new User();

        /** @var array<int, object> $entities */
        $entities = \iterator_to_array($provider->build($user), false);

        self::assertNotEmpty($entities, 'build() should yield at least one entity');

        $first = $entities[0];

        self::assertInstanceOf(Assignment::class, $first);
        self::assertSame('Arzt/Arzt', $first->getName());
        self::assertSame($user, $first->getCreatedBy());
    }

    public function testProvideReturnsExpectedAssignmentsInOrder(): void
    {
        $provider = new AssignmentSeedProvider();
        $values = \iterator_to_array($provider->provide(), false);

        self::assertSame('Arzt/Arzt', $values[0] ?? null);
        self::assertSame('ZLST', $values[\count($values) - 1] ?? null);

        self::assertContains('Einweisung', $values);
        self::assertContains('Patient', $values);

        self::assertSame($values, array_values(array_unique($values)));

        self::assertCount(7, $values);
    }

    public function testGetTypeIsAssignment(): void
    {
        self::assertSame('assignment', new AssignmentSeedProvider()->getType());
    }

    public function testPurgeTablesReturnAssignment(): void
    {
        $provider = new AssignmentSeedProvider();

        self::assertSame(['assignment'], $provider->purgeTables());
    }
}
