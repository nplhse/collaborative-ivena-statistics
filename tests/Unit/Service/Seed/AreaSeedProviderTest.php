<?php

namespace App\Tests\Unit\Service\Seed;

use App\Entity\DispatchArea;
use App\Entity\State;
use App\Entity\User;
use App\Service\Seed\AreaSeedProvider;
use PHPUnit\Framework\TestCase;

final class AreaSeedProviderTest extends TestCase
{
    public function testBuildYieldsStatesThenAreasWithRelations(): void
    {
        $provider = new AreaSeedProvider();
        $user = new User();

        /** @var list<object> $built */
        $built = \iterator_to_array($provider->build($user), false);

        self::assertNotEmpty($built);

        self::assertInstanceOf(State::class, $built[0]);

        /** @var State $firstState */
        $firstState = $built[0];
        self::assertSame('Hessen', $firstState->getName());
        self::assertSame($user, $firstState->getCreatedBy());

        $firstArea = null;
        foreach ($built as $obj) {
            if ($obj instanceof DispatchArea) {
                $firstArea = $obj;
                break;
            }
        }

        self::assertInstanceOf(DispatchArea::class, $firstArea);

        /* @var DispatchArea $firstArea */
        self::assertSame('Bergstraße', $firstArea->getName());
        self::assertSame($user, $firstArea->getCreatedBy());
        self::assertInstanceOf(State::class, $firstArea->getState());
        self::assertSame('Hessen', $firstArea->getState()->getName());
    }

    public function testProvideReturnsExpectedRowsInOrder(): void
    {
        $provider = new AreaSeedProvider();

        /** @var list<array{state:string, name:string}> $rows */
        $rows = \iterator_to_array($provider->provide(), false);

        self::assertSame(['state' => 'Hessen', 'name' => 'Bergstraße'], $rows[0] ?? null);
        self::assertSame(['state' => 'Bayern', 'name' => 'Bayerischer Untermain'], $rows[\count($rows) - 1] ?? null);

        self::assertTrue($this->containsPair($rows, 'Hessen', 'Darmstadt'));
        self::assertTrue($this->containsPair($rows, 'Hessen', 'Offenbach'));
        self::assertTrue($this->containsPair($rows, 'Hessen', 'Wiesbaden'));

        $keys = \array_map(static fn (array $r): string => $r['state'].'|'.$r['name'], $rows);
        self::assertSame($keys, \array_values(\array_unique($keys)));

        self::assertCount(25, $rows);
    }

    public function testPurgeTablesContainsExpectedNames(): void
    {
        $provider = new AreaSeedProvider();
        $tables = $provider->purgeTables();

        self::assertIsArray($tables);
        foreach ($tables as $t) {
            self::assertIsString($t);
        }

        self::assertContains('dispatch_area', $tables);
        self::assertContains('state', $tables);
    }

    public function testGetTypeIsArea(): void
    {
        self::assertSame('area', new AreaSeedProvider()->getType());
    }

    public function testPurgeTablesReturnAreas(): void
    {
        $provider = new AreaSeedProvider();

        self::assertSame(['dispatch_area', 'state'], $provider->purgeTables());
    }

    /**
     * @param list<array{state:string, name:string}> $rows
     */
    private function containsPair(array $rows, string $state, string $name): bool
    {
        foreach ($rows as $r) {
            if ($r['state'] === $state && $r['name'] === $name) {
                return true;
            }
        }

        return false;
    }
}
