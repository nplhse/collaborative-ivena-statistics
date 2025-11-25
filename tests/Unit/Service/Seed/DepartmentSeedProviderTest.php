<?php

namespace App\Tests\Unit\Service\Seed;

use App\Allocation\Domain\Entity\Department;
use App\Service\Seed\DepartmentSeedProvider;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class DepartmentSeedProviderTest extends TestCase
{
    public function testBuildCreatesFirstEntityWithExpectedNameAndCreatedBy(): void
    {
        $provider = new DepartmentSeedProvider();

        $user = new User();

        /** @var array<int, object> $entities */
        $entities = \iterator_to_array($provider->build($user), false);

        self::assertNotEmpty($entities, 'build() should yield at least one entity');

        $first = $entities[0];

        self::assertInstanceOf(Department::class, $first);
        self::assertSame('Akut- und Gerontopsych. / Isolierung', $first->getName());
        self::assertSame($user, $first->getCreatedBy());
    }

    public function testProvideReturnsExpectedSpecialitiesInOrder(): void
    {
        $provider = new DepartmentSeedProvider();
        $values = \iterator_to_array($provider->provide(), false);

        self::assertSame('Akut- und Gerontopsych. / Isolierung', $values[0] ?? null);
        self::assertContains('Kardiologie', $values);
        self::assertContains('Nuklearmedizin', $values);
        self::assertSame('Zu- Verlegung Sonderlage Ukraine', $values[\count($values) - 1] ?? null);

        self::assertSame($values, array_values(array_unique($values)));
        self::assertGreaterThanOrEqual(108, \count($values));
    }

    public function testGetTypeIsSpeciality(): void
    {
        self::assertSame('department', new DepartmentSeedProvider()->getType());
    }

    public function testPurgeTablesReturnDepartment(): void
    {
        $provider = new DepartmentSeedProvider();

        self::assertSame(['department'], $provider->purgeTables());
    }
}
