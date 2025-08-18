<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Address;
use App\Entity\DispatchArea;
use App\Entity\Hospital;
use App\Enum\HospitalLocation;
use App\Enum\HospitalSize;
use App\Enum\HospitalTier;
use PHPUnit\Framework\TestCase;

final class HospitalTest extends TestCase
{
    public function testConstructorInitializesDefaults(): void
    {
        $hospital = new Hospital();

        $this->assertNull($hospital->getUpdatedAt());
        $this->assertNull($hospital->getName());
        $this->assertNull($hospital->getBeds());
    }

    public function testToStringShowsNameOrFallback(): void
    {
        $hospital = new Hospital();
        $this->assertSame('No name', (string) $hospital);

        $hospital->setName('Test Hospital');
        $this->assertSame('Test Hospital', (string) $hospital);
    }

    public function testEnumsAndBedsAreStored(): void
    {
        $hospital = new Hospital();

        $hospital->setLocation(HospitalLocation::RURAL);
        $hospital->setTier(HospitalTier::BASIC);
        $hospital->setSize(HospitalSize::SMALL);
        $hospital->setBeds(321);

        $this->assertSame(HospitalLocation::RURAL, $hospital->getLocation());
        $this->assertSame(HospitalTier::BASIC, $hospital->getTier());
        $this->assertSame(HospitalSize::SMALL, $hospital->getSize());
        $this->assertSame(321, $hospital->getBeds());
    }

    public function testSetAddressReplacesEmbeddable(): void
    {
        $hospital = new Hospital();
        $address = new Address();

        $address->setStreet('Test Street 123');
        $address->setPostalCode('12345');
        $address->setCity('Testcity');
        $address->setCountry('Deutschland');

        $hospital->setAddress($address);

        $this->assertSame($address, $hospital->getAddress());
        $this->assertStringContainsString('Testcity', $hospital->getAddress()->getCity());
    }

    public function testAddHospitalSetsOwningSideAndIsIdempotent(): void
    {
        $area = new DispatchArea();
        $hospital = new Hospital();

        $area->addHospital($hospital);

        self::assertTrue($area->getHospitals()->contains($hospital));
        self::assertSame($area, $hospital->getDispatchArea());

        $area->addHospital($hospital);
        self::assertCount(1, $area->getHospitals());
    }

    public function testRemoveHospitalUnsetsOwningSide(): void
    {
        $area = new DispatchArea();
        $hospital = new Hospital();

        $area->addHospital($hospital);
        $area->removeHospital($hospital);

        self::assertFalse($area->getHospitals()->contains($hospital));
        self::assertNull($hospital->getDispatchArea());
    }
}
