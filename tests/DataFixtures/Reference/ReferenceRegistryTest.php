<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Reference;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use App\DataFixtures\Reference\ReferenceRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReferenceRegistryTest extends TestCase
{
    #[Test]
    public function getStateThrowsWhenUnknown(): void
    {
        $registry = new ReferenceRegistry($this->createMock(EntityManagerInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown state reference: Hessen');

        $registry->getState('Hessen');
    }

    #[Test]
    public function getDispatchAreaThrowsWhenUnknown(): void
    {
        $registry = new ReferenceRegistry($this->createMock(EntityManagerInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown dispatch area reference: Hessen / Frankfurt');

        $registry->getDispatchArea('Hessen', 'Frankfurt');
    }

    #[Test]
    public function registerAndGetStateReturnsSameInstance(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(true);

        $state = new State()->setName('Hessen');
        $registry = new ReferenceRegistry($em);
        $registry->registerState($state);

        self::assertSame($state, $registry->getState('Hessen'));
    }

    #[Test]
    public function registerAndGetDispatchAreaReturnsSameInstance(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(true);

        $state = new State()->setName('Hessen');
        $area = new DispatchArea()->setName('Frankfurt')->setState($state);

        $registry = new ReferenceRegistry($em);
        $registry->registerState($state);
        $registry->registerDispatchArea($area);

        self::assertSame($area, $registry->getDispatchArea('Hessen', 'Frankfurt'));
    }

    #[Test]
    public function getHospitalThrowsWhenUnknown(): void
    {
        $registry = new ReferenceRegistry($this->createMock(EntityManagerInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown hospital reference: Example Hospital');

        $registry->getHospital('Example Hospital');
    }

    #[Test]
    public function allHospitalsReturnsRegisteredHospitals(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(true);

        $hospitalA = new Hospital()->setName('Hospital A');
        $hospitalB = new Hospital()->setName('Hospital B');

        $registry = new ReferenceRegistry($em);
        $registry->registerHospital($hospitalA);
        $registry->registerHospital($hospitalB);

        self::assertSame([$hospitalA, $hospitalB], $registry->allHospitals());
        self::assertSame($hospitalA, $registry->getHospital('Hospital A'));
    }
}
