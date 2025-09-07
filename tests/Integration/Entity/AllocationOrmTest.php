<?php

declare(strict_types=1);

namespace App\Tests\Integration\Entity;

use App\Entity\Allocation;
use App\Entity\Department;
use App\Entity\DispatchArea;
use App\Entity\Hospital;
use App\Entity\Import;
use App\Entity\Speciality;
use App\Entity\State;
use App\Enum\AllocationGender;
use App\Enum\AllocationTransportType;
use App\Enum\AllocationUrgency;
use App\Factory\DepartmentFactory;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\ImportFactory;
use App\Factory\SpecialityFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AllocationOrmTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPersistAndHydrateRoundtrip(): void
    {
        UserFactory::createOne();

        $state = StateFactory::createOne(['name' => 'Hessen']);
        $area = DispatchAreaFactory::createOne(['name' => 'Alpha Area', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $area,
            'name' => 'St. Test Hospital',
        ]);
        $import = ImportFactory::createOne(['name' => 'Test Import']);

        $speciality = SpecialityFactory::createOne(['name' => 'Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Department']);

        $state = $this->em->getRepository(State::class)->find($state->getId());
        $area = $this->em->getRepository(DispatchArea::class)->find($area->getId());
        $hospital = $this->em->getRepository(Hospital::class)->find($hospital->getId());
        $import = $this->em->getRepository(Import::class)->find($import->getId());
        $speciality = $this->em->getRepository(Speciality::class)->find($speciality->getId());
        $department = $this->em->getRepository(Department::class)->find($department->getId());

        $createdAt = new \DateTimeImmutable('now');
        $arrivalAt = new \DateTimeImmutable('+10 minutes');

        $allocation = new Allocation()
            ->setHospital($hospital)
            ->setDispatchArea($area)
            ->setState($state)
            ->setImport($import)
            ->setCreatedAt($createdAt)
            ->setArrivalAt($arrivalAt)
            ->setGender(AllocationGender::FEMALE)
            ->setAge(67)
            ->setRequiresResus(true)
            ->setRequiresCathlab(false)
            ->setIsCPR(false)
            ->setIsVentilated(true)
            ->setIsShock(false)
            ->setIsPregnant(false)
            ->setIsWithPhysician(true)
            ->setTransportType(AllocationTransportType::GROUND)
            ->setUrgency(AllocationUrgency::IMMEDIATE)
            ->setSpeciality($speciality)
            ->setDepartment($department)
            ->setDepartmentWasClosed(false)
        ;

        $this->em->persist($allocation);
        $this->em->flush();
        $id = $allocation->getId();

        $this->em->clear();

        /** @var Allocation $object */
        $object = $this->em->getRepository(Allocation::class)->find($id);
        self::assertNotNull($object);

        // Enums & simple fields roundtrip
        self::assertSame(AllocationGender::FEMALE, $object->getGender());
        self::assertSame(AllocationTransportType::GROUND, $object->getTransportType());
        self::assertSame(67, $object->getAge());
        self::assertSame($createdAt->getTimestamp(), $object->getCreatedAt()?->getTimestamp());
        self::assertSame($arrivalAt->getTimestamp(), $object->getArrivalAt()?->getTimestamp());

        // Flags
        self::assertTrue($object->isRequiresResus());
        self::assertFalse($object->isRequiresCathlab());
        self::assertFalse($object->isCPR());
        self::assertTrue($object->isVentilated());
        self::assertFalse($object->isShock());
        self::assertFalse($object->isPregnant());
        self::assertTrue($object->isWithPhysician());
        self::assertSame(AllocationUrgency::IMMEDIATE, $object->getUrgency());
        self::assertFalse($object->isDepartmentWasClosed());

        // Relations
        self::assertSame('St. Test Hospital', $object->getHospital()?->getName());
        self::assertSame('Alpha Area', $object->getDispatchArea()?->getName());
        self::assertSame('Hessen', $object->getState()?->getName());
        self::assertSame('Test Import', $object->getImport()->getName());

        // Specialities
        self::assertSame('Speciality', $object->getSpeciality()->getName());
        self::assertSame('Department', $object->getDepartment()->getName());
    }

    public function testTransportTypeCanBeNull(): void
    {
        UserFactory::createOne();

        $state = StateFactory::createOne();
        $area = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $area]);
        $import = ImportFactory::createOne(['name' => 'Test Import']);
        $speciality = SpecialityFactory::createOne(['name' => 'Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Department']);

        $state = $this->em->getRepository(State::class)->find($state->getId());
        $area = $this->em->getRepository(DispatchArea::class)->find($area->getId());
        $hospital = $this->em->getRepository(Hospital::class)->find($hospital->getId());
        $import = $this->em->getRepository(Import::class)->find($import->getId());
        $speciality = $this->em->getRepository(Speciality::class)->find($speciality->getId());
        $department = $this->em->getRepository(Department::class)->find($department->getId());

        $allocation = new Allocation()
            ->setHospital($hospital)
            ->setDispatchArea($area)
            ->setState($state)
            ->setImport($import)
            ->setCreatedAt(new \DateTimeImmutable('now'))
            ->setArrivalAt(new \DateTimeImmutable('+10 minutes'))
            ->setGender(AllocationGender::OTHER)
            ->setAge(30)
            ->setRequiresResus(false)
            ->setRequiresCathlab(false)
            ->setIsCPR(false)
            ->setIsVentilated(false)
            ->setIsShock(false)
            ->setIsPregnant(false)
            ->setIsWithPhysician(false)
            ->setTransportType(null)
            ->setUrgency(AllocationUrgency::IMMEDIATE)
            ->setSpeciality($speciality)
            ->setDepartment($department)
            ->setDepartmentWasClosed(false)
        ;

        $this->em->persist($allocation);
        $this->em->flush();
        $id = $allocation->getId();

        $this->em->clear();

        /** @var Allocation $object */
        $object = $this->em->getRepository(Allocation::class)->find($id);
        self::assertNull($object->getTransportType());
    }
}
