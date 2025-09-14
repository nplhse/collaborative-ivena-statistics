<?php

namespace App\Tests\Unit\Service\Seed;

use App\Service\Seed\InfectionSeedProvider;
use PHPUnit\Framework\TestCase;

final class InfectionSeedProviderTest extends TestCase
{
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
}
