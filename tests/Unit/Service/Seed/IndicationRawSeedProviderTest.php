<?php

namespace App\Tests\Unit\Service\Seed;

use App\Entity\IndicationRaw;
use App\Entity\User;
use App\Service\Seed\IndicationRawSeedProvider;
use PHPUnit\Framework\TestCase;

final class IndicationRawSeedProviderTest extends TestCase
{
    public function testBuildCreatesFirstEntityWithExpectedNameAndCreatedBy(): void
    {
        $provider = new IndicationRawSeedProvider();

        $user = new User();

        /** @var array<int, object> $entities */
        $entities = \iterator_to_array($provider->build($user), false);

        self::assertNotEmpty($entities, 'build() should yield at least one entity');

        $first = $entities[0];

        self::assertInstanceOf(IndicationRaw::class, $first);
        self::assertSame(111, $first->getCode());
        self::assertSame('primäre Todesfeststellung', $first->getName());
        self::assertSame($user, $first->getCreatedBy());
    }

    public function testProvideReturnsExpectedInfectionsInOrder(): void
    {
        $provider = new IndicationRawSeedProvider();
        $values = \iterator_to_array($provider->provide(), false);

        self::assertSame(['code' => '111', 'name' => 'primäre Todesfeststellung'], $values[0] ?? null);
        self::assertSame(['code' => '770', 'name' => 'sonstige Notfallsituation'], $values[\count($values) - 1] ?? null);

        self::assertTrue(self::containsPair($values, '323', 'Hypertonie'));
        self::assertTrue(self::containsPair($values, '431', 'Akute Suizidalität'));
        self::assertTrue(self::containsPair($values, '721', 'Augenverletzung mit Fremdkörper'));

        /** @var list<string> $keys */
        $keys = \array_map(
            static fn (array $row): string => $row['code'].'|'.$row['name'],
            $values
        );

        self::assertSame($keys, \array_values(\array_unique($keys)));

        self::assertCount(200, $values);
    }

    public function testGetTypeIsInfection(): void
    {
        self::assertSame('indication_raw', new IndicationRawSeedProvider()->getType());
    }

    public function testPurgeTablesReturnIndicationRaw(): void
    {
        $provider = new IndicationRawSeedProvider();

        self::assertSame(['indication_raw'], $provider->purgeTables());
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
