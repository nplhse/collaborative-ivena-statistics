<?php

namespace App\Tests\Unit\Service\Seed;

use App\Service\Seed\AssignmentSeedProvider;
use PHPUnit\Framework\TestCase;

final class AssignmentSeedProviderTest extends TestCase
{
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
}
