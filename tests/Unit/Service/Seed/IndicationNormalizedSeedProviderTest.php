<?php

namespace App\Tests\Unit\Service\Seed;

use App\Service\Seed\IndicationNormalizedSeedProvider;
use PHPUnit\Framework\TestCase;

final class IndicationNormalizedSeedProviderTest extends TestCase
{
    public function testProvideReturnsExpectedInfectionsInOrder(): void
    {
        $provider = new IndicationNormalizedSeedProvider();
        $values = \iterator_to_array($provider->provide(), false);

        self::assertSame(['code' => '000', 'name' => 'Kein Patient vorhanden'], $values[0] ?? null);
        self::assertSame(['code' => '809', 'name' => 'Allgemeinmedizin, sonstiger Notfall'], $values[\count($values) - 1] ?? null);

        self::assertTrue(self::containsPair($values, '271', 'Extremitäten offen'));
        self::assertTrue(self::containsPair($values, '393', 'Hypoglykämie'));
        self::assertTrue(self::containsPair($values, '715', 'Katheterwechsel (transurethral)'));

        /** @var list<string> $keys */
        $keys = \array_map(
            static fn (array $row): string => $row['code'].'|'.$row['name'],
            $values
        );

        self::assertSame($keys, \array_values(\array_unique($keys)));

        self::assertCount(210, $values);
    }

    public function testGetTypeIsIndicationNormalized(): void
    {
        self::assertSame('indication_normalized', new IndicationNormalizedSeedProvider()->getType());
    }

    /**
     * @param array<int, array{code:string, name:string}> $values
     */
    private static function containsPair(array $values, string $code, string $name): bool
    {
        foreach ($values as $row) {
            if ($row['code'] === $code && $row['name'] === $name) {
                return true;
            }
        }

        return false;
    }
}
