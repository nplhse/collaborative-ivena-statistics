<?php

namespace App\Tests\Unit\Service\Seed;

use App\Entity\IndicationNormalized;
use App\Service\Seed\IndicationNormalizedSeedProvider;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class IndicationNormalizedSeedProviderTest extends TestCase
{
    public function testBuildCreatesFirstEntityWithExpectedNameAndCreatedBy(): void
    {
        $provider = new IndicationNormalizedSeedProvider();

        $user = new User();

        /** @var array<int, object> $entities */
        $entities = \iterator_to_array($provider->build($user), false);

        self::assertNotEmpty($entities, 'build() should yield at least one entity');

        $first = $entities[0];

        self::assertInstanceOf(IndicationNormalized::class, $first);
        self::assertSame(000, $first->getCode());
        self::assertSame('Kein Patient vorhanden', $first->getName());
        self::assertSame($user, $first->getCreatedBy());
    }

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

    public function testPurgeTablesReturnIndicationNormalized(): void
    {
        $provider = new IndicationNormalizedSeedProvider();

        self::assertSame(['indication_normalized'], $provider->purgeTables());
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
