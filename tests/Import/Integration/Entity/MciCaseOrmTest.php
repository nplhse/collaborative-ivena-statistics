<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Entity;

use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MciCaseOrmTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPersistAndHydrateRoundtrip(): void
    {
        UserFactory::createOne(['username' => 'mci-case-orm-roundtrip-'.bin2hex(random_bytes(6))]);

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

        $occasion = OccasionFactory::createOne(['name' => 'Test Occasion']);
        $infection = InfectionFactory::createOne(['name' => 'Test Infection']);

        $indicationRaw = IndicationRawFactory::createOne(['name' => 'Test IndicationRaw', 'code' => 123]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'Test IndicationNormalized', 'code' => 123]);

        $state = $this->em->getRepository(State::class)->find($state->getId());
        $area = $this->em->getRepository(DispatchArea::class)->find($area->getId());
        $hospital = $this->em->getRepository(Hospital::class)->find($hospital->getId());
        $import = $this->em->getRepository(Import::class)->find($import->getId());
        $speciality = $this->em->getRepository(Speciality::class)->find($speciality->getId());
        $department = $this->em->getRepository(Department::class)->find($department->getId());
        $occasion = $this->em->getRepository(Occasion::class)->find($occasion->getId());
        $infection = $this->em->getRepository(Infection::class)->find($infection->getId());
        $indicationRaw = $this->em->getRepository(IndicationRaw::class)->find($indicationRaw->getId());
        $indicationNormalized = $this->em->getRepository(IndicationNormalized::class)->find($indicationNormalized->getId());

        $createdAt = new \DateTimeImmutable('now');
        $arrivalAt = $createdAt->add(new \DateInterval('PT10M'));

        $mciCase = new MciCase()
            ->setHospital($hospital)
            ->setDispatchArea($area)
            ->setState($state)
            ->setImport($import)
            ->setCreatedAt($createdAt)
            ->setArrivalAt($arrivalAt)
            ->setMciId('mci-hash-1')
            ->setMciTitle('mci-title-1')
            ->setGender(AllocationGender::FEMALE)
            ->setAge(67)
            ->setRequiresResus(true)
            ->setRequiresCathlab(null)
            ->setIsCPR(false)
            ->setIsVentilated(null)
            ->setIsShock(true)
            ->setIsPregnant(false)
            ->setIsWithPhysician(null)
            ->setTransportType(AllocationTransportType::AIR)
            ->setUrgency(AllocationUrgency::INPATIENT)
            ->setSpeciality($speciality)
            ->setDepartment($department)
            ->setDepartmentWasClosed(null)
            ->setOccasion($occasion)
            ->setInfection(null)
            ->setIndicationRaw($indicationRaw)
            ->setIndicationNormalized(null)
        ;

        $this->em->persist($mciCase);
        $this->em->flush();
        $id = $mciCase->getId();

        $this->em->clear();

        /** @var MciCase $object */
        $object = $this->em->getRepository(MciCase::class)->find($id);

        self::assertNotNull($object);

        // Required identifiers
        self::assertSame('mci-hash-1', $object->getMciId());
        self::assertSame('mci-title-1', $object->getMciTitle());

        // Enums & simple fields
        self::assertSame(AllocationGender::FEMALE, $object->getGender());
        self::assertSame(67, $object->getAge());
        self::assertSame(AllocationTransportType::AIR, $object->getTransportType());
        self::assertSame(AllocationUrgency::INPATIENT, $object->getUrgency());

        self::assertSame($createdAt->getTimestamp(), $object->getCreatedAt()?->getTimestamp());
        self::assertSame($arrivalAt->getTimestamp(), $object->getArrivalAt()?->getTimestamp());

        // Optional flags (nullable booleans)
        self::assertTrue($object->isRequiresResus());
        self::assertNull($object->isRequiresCathlab());
        self::assertFalse($object->isCPR());
        self::assertNull($object->isVentilated());
        self::assertTrue($object->isShock());
        self::assertFalse($object->isPregnant());
        self::assertNull($object->isWithPhysician());

        // Optional relations
        self::assertSame('Speciality', $object->getSpeciality()?->getName());
        self::assertSame('Department', $object->getDepartment()?->getName());
        self::assertNull($object->isDepartmentWasClosed());
        self::assertSame('Test Occasion', $object->getOccasion()?->getName());
        self::assertNull($object->getInfection());
        self::assertSame('Test IndicationRaw', $object->getIndicationRaw()?->getName());
        self::assertNull($object->getIndicationNormalized());

        // Required relations
        self::assertSame('St. Test Hospital', $object->getHospital()?->getName());
        self::assertSame('Alpha Area', $object->getDispatchArea()?->getName());
        self::assertSame('Hessen', $object->getState()?->getName());
        self::assertSame('Test Import', $object->getImport()?->getName());
    }
}
