<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Entity;

use App\Allocation\Domain\Entity\Address;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use PHPUnit\Framework\TestCase;

final class HospitalTest extends TestCase
{
    public function testConstructorInitializesDefaults(): void
    {
        $hospital = new Hospital();

        $this->assertNull($hospital->getUpdatedAt());
        $this->assertNull($hospital->getParticipatingSince());
        $this->assertNull($hospital->getName());
        $this->assertNull($hospital->getBeds());
        $this->assertSame('', $hospital->getAddress()->getStreet());
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

    public function testRejoiningDoesNotOverwriteParticipatingSince(): void
    {
        $hospital = new Hospital();
        $firstJoin = new \DateTimeImmutable('2024-06-01 12:00:00', new \DateTimeZone('UTC'));
        $hospital->setParticipatingSince($firstJoin);
        $hospital->setIsParticipating(true);

        $hospital->setIsParticipating(false);
        self::assertSame('2024-06-01T12:00:00+00:00', $this->atom($hospital->getParticipatingSince()));

        $hospital->setIsParticipating(true);
        self::assertSame('2024-06-01T12:00:00+00:00', $this->atom($hospital->getParticipatingSince()));
    }

    public function testPrePersistCopiesCreatedAtWhenParticipatingWithoutTimestamp(): void
    {
        $hospital = new Hospital();
        $createdAt = new \DateTimeImmutable('2024-03-01 10:00:00', new \DateTimeZone('UTC'));
        $hospital->setCreatedAt($createdAt);

        $hospital->ensureParticipatingSinceOnPersist();

        self::assertSame('2024-03-01T10:00:00+00:00', $this->atom($hospital->getParticipatingSince()));
    }

    public function testPrePersistDoesNotSetTimestampWhenNotParticipating(): void
    {
        $hospital = new Hospital();
        $hospital->setIsParticipating(false);

        $hospital->ensureParticipatingSinceOnPersist();

        self::assertNull($hospital->getParticipatingSince());
    }

    public function testPrePersistDoesNotOverwriteExistingParticipatingSince(): void
    {
        $hospital = new Hospital();
        $createdAt = new \DateTimeImmutable('2024-03-01 10:00:00', new \DateTimeZone('UTC'));
        $existing = new \DateTimeImmutable('2025-01-15 08:00:00', new \DateTimeZone('UTC'));
        $hospital->setCreatedAt($createdAt);
        $hospital->setParticipatingSince($existing);

        $hospital->ensureParticipatingSinceOnPersist();

        self::assertSame('2025-01-15T08:00:00+00:00', $this->atom($hospital->getParticipatingSince()));
    }

    private function atom(?\DateTimeImmutable $value): string
    {
        if (!$value instanceof \DateTimeImmutable) {
            return '';
        }

        return $value->format(\DateTimeInterface::ATOM);
    }
}
