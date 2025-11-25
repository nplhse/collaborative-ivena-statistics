<?php

namespace App\Tests\Import\Integration\Service\Mapping;

use App\Enum\AllocationGender;
use App\Enum\AllocationTransportType;
use App\Factory\AssignmentFactory;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\ImportFactory;
use App\Factory\IndicationRawFactory;
use App\Factory\InfectionFactory;
use App\Factory\OccasionFactory;
use App\Factory\StateFactory;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Application\Exception\InvalidDateException;
use App\Import\Application\Exception\InvalidEnumException;
use App\Import\Application\Exception\ReferenceNotFoundException;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Mapping\AllocationImportFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AllocationImportFactoryTest extends KernelTestCase
{
    use ResetDatabase;

    private int $hospitalId;
    private Import $import;
    private AllocationImportFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        UserFactory::createOne();

        $state = StateFactory::createOne(['name' => 'Test State']);
        $area = DispatchAreaFactory::createOne(['name' => 'Test Area', 'state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Test Hospital', 'state' => $state, 'dispatchArea' => $area]);
        $import = ImportFactory::createOne(['name' => 'Test Import', 'hospital' => $hospital]);

        AssignmentFactory::createOne(['name' => 'Patient']);
        OccasionFactory::createOne(['name' => 'Sonstiger Einsatz']);
        InfectionFactory::createOne(['name' => '3MRGN']);
        IndicationRawFactory::createOne(['name' => 'Test Indication', 'code' => 123]);

        $this->import = $em->getRepository(Import::class)->find($import->getId());

        $this->factory = self::getContainer()->get(AllocationImportFactory::class);
        $this->factory->warm();
    }

    /**
     * @param array<string, mixed> $override
     */
    private function makeDto(array $override = []): AllocationRowDTO
    {
        $dto = new AllocationRowDTO();
        $dto->dispatchArea = 'Test Area';
        $dto->createdAt = '07.01.2025 10:19';
        $dto->arrivalAt = '07.01.2025 13:14';
        $dto->gender = 'M';
        $dto->age = 74;
        $dto->requiresResus = true;
        $dto->requiresCathlab = true;
        $dto->isCPR = true;
        $dto->isVentilated = false;
        $dto->isShock = false;
        $dto->isPregnant = false;
        $dto->isWithPhysician = true;
        $dto->transportType = 'G';
        $dto->urgency = 1;
        $dto->assignment = 'Patient';
        $dto->occasion = 'Sonstiger Einsatz';
        $dto->infection = '3MRGN';
        $dto->indication = 'Test Indication';
        $dto->indicationCode = 123;

        foreach ($override as $k => $v) {
            $dto->$k = $v;
        }

        return $dto;
    }

    public function testFromDtoSuccess(): void
    {
        $allocation = $this->factory->fromDto($this->makeDto(), $this->import);

        self::assertSame('Test Hospital', $allocation->getHospital()->getName());
        self::assertSame('Test Area', $allocation->getDispatchArea()->getName());

        self::assertSame('2025-01-07 10:19', $allocation->getCreatedAt()->format('Y-m-d H:i'));
        self::assertSame('2025-01-07 13:14', $allocation->getArrivalAt()->format('Y-m-d H:i'));

        self::assertSame(AllocationGender::MALE, $allocation->getGender());
        self::assertSame(74, $allocation->getAge());
        self::assertTrue($allocation->isRequiresResus());
        self::assertTrue($allocation->isRequiresCathlab());
        self::assertTrue($allocation->isCPR());
        self::assertFalse($allocation->isVentilated());
        self::assertFalse($allocation->isShock());
        self::assertFalse($allocation->isPregnant());
        self::assertTrue($allocation->isWithPhysician());

        self::assertSame(AllocationTransportType::tryFrom('G'), $allocation->getTransportType());

        self::assertSame('Test Indication', $allocation->getIndicationRaw()->getName());
    }

    public function testUnknownDispatchAreaThrows(): void
    {
        $this->expectException(ReferenceNotFoundException::class);
        $this->factory->fromDto($this->makeDto(['dispatchArea' => 'XYZ']), $this->import);
    }

    public function testInvalidGenderThrows(): void
    {
        $this->expectException(InvalidEnumException::class);
        $this->factory->fromDto($this->makeDto(['gender' => 'Q']), $this->import);
    }

    public function testInvalidArrivalDateThrows(): void
    {
        $this->expectException(InvalidDateException::class);
        $this->factory->fromDto($this->makeDto(['arrivalAt' => '31.02.2025 25:61']), $this->import);
    }
}
