<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service\Mapping;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\DTO\MciCaseRowDTO;
use App\Import\Application\Exception\ReferenceNotFoundException;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Mapping\MciCaseImportFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class MciCaseImportFactoryTest extends KernelTestCase
{
    use ResetDatabase;

    private Import $import;
    private MciCaseImportFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        UserFactory::createOne();

        $state = StateFactory::createOne(['name' => 'Test State']);
        $area = DispatchAreaFactory::createOne(['name' => 'Test Area', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Test Hospital',
            'state' => $state,
            'dispatchArea' => $area,
        ]);

        $this->import = ImportFactory::createOne(['name' => 'Test Import', 'hospital' => $hospital]);

        $speciality = SpecialityFactory::createOne(['name' => 'Test Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Test Department']);

        OccasionFactory::createOne(['name' => 'Sonstiger Einsatz']);
        InfectionFactory::createOne(['name' => '3MRGN']);

        IndicationRawFactory::createOne(['name' => 'Test Indication', 'code' => 123]);

        $this->import = $em->getRepository(Import::class)->find($this->import->getId());

        $this->factory = self::getContainer()->get(MciCaseImportFactory::class);
        $this->factory->warm();
    }

    /**
     * @param array<string, mixed> $override
     */
    private function makeDto(array $override = []): MciCaseRowDTO
    {
        $dto = new MciCaseRowDTO();
        $dto->dispatchArea = 'Test Area';
        $dto->hospital = 'KH Test';
        $dto->createdAt = '07.01.2025 10:19';
        $dto->arrivalAt = '07.01.2025 13:14';

        $dto->mciId = 'mci-hash-1';
        $dto->mciTitle = 'mci-title-1';

        $dto->gender = AllocationGender::MALE->value;
        $dto->age = 74;

        $dto->requiresResus = true;
        $dto->requiresCathlab = null;

        $dto->isCPR = true;
        $dto->isVentilated = false;
        $dto->isShock = false;
        $dto->isPregnant = false;
        $dto->isWithPhysician = null;

        $dto->transportType = AllocationTransportType::GROUND->value;
        $dto->urgency = AllocationUrgency::EMERGENCY->value;

        $dto->speciality = 'Test Speciality';
        $dto->department = 'Test Department';
        $dto->departmentWasClosed = false;

        $dto->occasion = 'Sonstiger Einsatz';
        $dto->infection = '3MRGN';

        $dto->indicationCode = 123;
        $dto->indication = 'Test Indication';

        foreach ($override as $k => $v) {
            $dto->$k = $v;
        }

        return $dto;
    }

    public function testFromDtoSuccess(): void
    {
        $mciCase = $this->factory->fromDto($this->makeDto(), $this->import);

        self::assertSame('Test Hospital', $mciCase->getHospital()->getName());
        self::assertSame('Test Area', $mciCase->getDispatchArea()->getName());
        self::assertSame('Test State', $mciCase->getState()->getName());

        self::assertSame('2025-01-07 10:19', $mciCase->getCreatedAt()->format('Y-m-d H:i'));
        self::assertSame('2025-01-07 13:14', $mciCase->getArrivalAt()->format('Y-m-d H:i'));

        self::assertSame(AllocationGender::MALE, $mciCase->getGender());
        self::assertSame(74, $mciCase->getAge());

        self::assertTrue($mciCase->isRequiresResus());
        self::assertNull($mciCase->isRequiresCathlab());
        self::assertTrue($mciCase->isCPR());
        self::assertFalse($mciCase->isVentilated());

        self::assertSame(AllocationTransportType::GROUND, $mciCase->getTransportType());
        self::assertSame(AllocationUrgency::EMERGENCY, $mciCase->getUrgency());

        self::assertSame('Test Speciality', $mciCase->getSpeciality()->getName());
        self::assertSame('Test Department', $mciCase->getDepartment()->getName());
        self::assertFalse($mciCase->isDepartmentWasClosed());

        self::assertSame('Sonstiger Einsatz', $mciCase->getOccasion()->getName());
        self::assertSame('3MRGN', $mciCase->getInfection()->getName());

        self::assertSame('Test Indication', $mciCase->getIndicationRaw()->getName());
        self::assertNull($mciCase->getIndicationNormalized());

        self::assertSame('mci-hash-1', $mciCase->getMciId());
        self::assertSame('mci-title-1', $mciCase->getMciTitle());
    }

    public function testUnknownDispatchAreaThrows(): void
    {
        $this->expectException(ReferenceNotFoundException::class);
        $this->factory->fromDto($this->makeDto(['dispatchArea' => 'XYZ']), $this->import);
    }
}
