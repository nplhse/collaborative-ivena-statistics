<?php

namespace App\Tests\Unit\Service\Seed;

use App\Service\Seed\OccasionSeedProvider;
use PHPUnit\Framework\TestCase;

final class OccasionSeedProviderTest extends TestCase
{
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
}
