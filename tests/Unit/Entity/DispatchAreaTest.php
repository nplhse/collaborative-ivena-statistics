<?php

declare(strict_types=1);

namespace App\Test\Unit\Entity;

use App\Entity\DispatchArea;
use App\Entity\Hospital;
use PHPUnit\Framework\TestCase;

final class DispatchAreaTest extends TestCase
{
    public function testConstructorInitializesDefaults(): void
    {
        $area = new DispatchArea();

        $this->assertCount(0, $area->getHospitals());
    }

    public function testToStringShowsNameOrFallback(): void
    {
        $area = new DispatchArea();
        $this->assertSame('No name', (string) $area);

        $area->setName('Test Area');
        $this->assertSame('Test Area', (string) $area);
    }

    public function testAddHospitalSetsBothSidesAndIsIdempotent(): void
    {
        $area = new DispatchArea();
        $hospital = new Hospital();

        $area->addHospital($hospital);

        $this->assertSame($area, $hospital->getDispatchArea());
        $this->assertTrue($area->getHospitals()->contains($hospital));

        $area->addHospital($hospital);
        $this->assertCount(1, $area->getHospitals());
    }

    public function testRemoveHospitalUnsetsOwningSide(): void
    {
        $area = new DispatchArea();
        $hospital = new Hospital();

        $area->addHospital($hospital);
        $area->removeHospital($hospital);

        $this->assertFalse($area->getHospitals()->contains($hospital));
        $this->assertNull($hospital->getDispatchArea());
    }
}
