<?php

namespace App\Tests\Unit\Service\Seed;

use App\Service\Seed\SpecialitySeedProvider;
use PHPUnit\Framework\TestCase;

final class SpecialitySeedProviderTest extends TestCase
{
    public function testProvideReturnsExpectedSpecialitiesInOrder(): void
    {
        $provider = new SpecialitySeedProvider();

        $gen = $provider->provide();
        self::assertInstanceOf(\Generator::class, $gen, 'provide() must return a Generator');

        $values = \iterator_to_array($gen, false);

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
}
