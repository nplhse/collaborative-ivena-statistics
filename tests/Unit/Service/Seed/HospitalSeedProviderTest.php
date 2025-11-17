<?php

namespace App\Tests\Unit\Service\Seed;

use App\Entity\DispatchArea;
use App\Entity\Hospital;
use App\Entity\State;
use App\Entity\User;
use App\Enum\HospitalLocation;
use App\Enum\HospitalSize;
use App\Enum\HospitalTier;
use App\Service\Seed\Areas\AreaCache;
use App\Service\Seed\HospitalSeedProvider;
use PHPUnit\Framework\TestCase;

final class HospitalSeedProviderTest extends TestCase
{
    public function testBuildCreatesFirstHospitalWithExpectedFields(): void
    {
        $areaCache = $this->createMock(AreaCache::class);
        $areaCache->expects($this->once())->method('warmUp');

        $areaCache
            ->method('hasState')
            ->willReturnCallback(static fn (string $state): bool => 'Hessen' === $state);

        $areaCache
            ->method('hasArea')
            ->willReturnCallback(static fn (string $state, string $area): bool => 'Hessen' === $state && 'Frankfurt' === $area);

        $areaCache
            ->method('getStateRef')
            ->willReturn(new State());

        $areaCache
            ->method('getAreaRef')
            ->willReturn(new DispatchArea());

        $provider = new HospitalSeedProvider($areaCache);
        $user = new User();

        $first = null;
        foreach ($provider->build($user) as $hospital) {
            $first = $hospital;
            break;
        }

        self::assertInstanceOf(Hospital::class, $first);

        self::assertSame('Agaplesion Bethanien Krankenhaus', $first->getName());
        self::assertSame(204, $first->getBeds());
        self::assertSame(HospitalTier::BASIC, $first->getTier());
        self::assertSame(HospitalSize::MEDIUM, $first->getSize());
        self::assertSame(HospitalLocation::URBAN, $first->getLocation());
        self::assertNull($first->getOwner());
        self::assertSame($user, $first->getCreatedBy());
        self::assertInstanceOf(State::class, $first->getState());
        self::assertInstanceOf(DispatchArea::class, $first->getDispatchArea());

        $addr = $first->getAddress();
        self::assertSame('Im Prüfling 21-25', $addr->getStreet());
        self::assertSame('Frankfurt am Main', $addr->getCity());
        self::assertSame('Hessen', $addr->getState());
        self::assertSame('60389', $addr->getPostalCode());
        self::assertSame('Deutschland', $addr->getCountry());
    }

    public function testBuildThrowsOnUnknownState(): void
    {
        $areaCache = $this->createMock(AreaCache::class);
        $areaCache->method('warmUp');
        $areaCache->method('hasState')->willReturn(false);

        $provider = new HospitalSeedProvider($areaCache);
        $user = new User();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown state');

        foreach ($provider->build($user) as $ignored) {
            // Awaiting Exception
        }
    }

    public function testBuildThrowsOnUnknownArea(): void
    {
        $areaCache = $this->createMock(AreaCache::class);
        $areaCache->method('warmUp');
        $areaCache->method('hasState')->willReturn(true);
        $areaCache->method('hasArea')->willReturn(false);

        $provider = new HospitalSeedProvider($areaCache);
        $user = new User();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown area');

        foreach ($provider->build($user) as $_) {
            // Awaiting Exception
        }
    }

    public function testProvideReturnsExpectedHospitalsInOrder(): void
    {
        $provider = new HospitalSeedProvider($this->createMock(AreaCache::class));

        /** @var array<int, array<string, mixed>> $rows */
        $rows = \iterator_to_array($provider->provide(), false);

        self::assertSame('Agaplesion Bethanien Krankenhaus', $rows[0]['name'] ?? null);
        self::assertSame('Universitätsklinikum Gießen und Marburg, Standort Marburg', $rows[\count($rows) - 1]['name'] ?? null);

        $allNames = array_map(static fn ($r) => $r['name'] ?? null, $rows);
        self::assertContains('Klinikum Kassel', $allNames);
        self::assertContains('St. Josefs-Hospital Wiesbaden', $allNames);

        $first = $rows[0];
        self::assertSame('Hessen', $first['state']);
        self::assertSame('Frankfurt', $first['area']);
        self::assertIsArray($first['address']);
        self::assertSame('Deutschland', $first['address']['country']);
    }

    public function testGetTypeIsSpeciality(): void
    {
        self::assertSame('hospital', new HospitalSeedProvider($this->createMock(AreaCache::class))->getType());
    }

    public function testPurgeTablesReturnAssignment(): void
    {
        $provider = new HospitalSeedProvider($this->createMock(AreaCache::class));

        self::assertSame(['hospital'], $provider->purgeTables());
    }
}
