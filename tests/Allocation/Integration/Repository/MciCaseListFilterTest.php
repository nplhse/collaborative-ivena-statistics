<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Repository;

use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\MciCaseFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Repository\MciCaseRepository;
use App\Allocation\UI\Http\DTO\MciCaseQueryParametersDTO;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class MciCaseListFilterTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testStateUrgencyAndTransportFilters(): void
    {
        $state = StateFactory::createOne();
        $otherState = StateFactory::createOne();
        $this->createCase('match', state: $state, urgency: AllocationUrgency::EMERGENCY, transportType: AllocationTransportType::GROUND);
        $this->createCase('other', state: $otherState, urgency: AllocationUrgency::OUTPATIENT, transportType: AllocationTransportType::AIR);

        self::assertSame(['match'], $this->matchingIds(new MciCaseQueryParametersDTO(state: $state->getId())));
        self::assertSame(['match'], $this->matchingIds(new MciCaseQueryParametersDTO(urgency: (string) AllocationUrgency::EMERGENCY->value)));
        self::assertSame(['match'], $this->matchingIds(new MciCaseQueryParametersDTO(transportType: AllocationTransportType::GROUND->value)));
        self::assertSame(['match', 'other'], $this->matchingIds(new MciCaseQueryParametersDTO(urgency: 'not-a-urgency')));
    }

    public function testDepartmentSpecialityAndClosedFilters(): void
    {
        $user = UserFactory::createOne();
        $department = DepartmentFactory::createOne(['createdBy' => $user]);
        $speciality = SpecialityFactory::createOne(['createdBy' => $user]);
        $this->createCase('match', department: $department, speciality: $speciality, departmentWasClosed: true);
        $this->createCase('other', departmentWasClosed: false);

        self::assertSame(['match'], $this->matchingIds(new MciCaseQueryParametersDTO(department: $department->getId())));
        self::assertSame(['match'], $this->matchingIds(new MciCaseQueryParametersDTO(speciality: $speciality->getId())));
        self::assertSame(['match'], $this->matchingIds(new MciCaseQueryParametersDTO(departmentWasClosed: 1)));
    }

    public function testClinicalIndicationOccasionAndInfectionFilters(): void
    {
        $user = UserFactory::createOne();
        $indication = IndicationNormalizedFactory::createOne(['code' => 4242, 'createdBy' => $user]);
        $occasion = OccasionFactory::createOne(['createdBy' => $user]);
        $infection = InfectionFactory::createOne();
        $this->createCase(
            'match',
            requiresResus: true,
            isWithPhysician: true,
            indication: $indication,
            occasion: $occasion,
            infection: $infection,
        );
        $this->createCase('plain', requiresResus: false, isWithPhysician: false);

        self::assertSame(['match'], $this->matchingIds(new MciCaseQueryParametersDTO(requiresResus: 1)));
        self::assertSame(['match'], $this->matchingIds(new MciCaseQueryParametersDTO(isWithPhysician: 1)));
        self::assertSame(['match'], $this->matchingIds(new MciCaseQueryParametersDTO(indication: 4242)));
        self::assertSame(['match'], $this->matchingIds(new MciCaseQueryParametersDTO(occasion: (string) $occasion->getId())));
        self::assertSame(['plain'], $this->matchingIds(new MciCaseQueryParametersDTO(occasion: 'none')));
        self::assertSame(['match'], $this->matchingIds(new MciCaseQueryParametersDTO(infection: (string) $infection->getId())));
        self::assertSame(['match'], $this->matchingIds(new MciCaseQueryParametersDTO(infection: 'any')));
        self::assertSame(['plain'], $this->matchingIds(new MciCaseQueryParametersDTO(infection: 'none')));
    }

    public function testBlankTextFiltersDoNotRestrictTheList(): void
    {
        $this->createCase('alpha');
        $this->createCase('beta');

        self::assertSame(
            ['alpha', 'beta'],
            $this->matchingIds(new MciCaseQueryParametersDTO(mciId: '  ', search: '')),
        );
    }

    /**
     * @return list<string>
     */
    private function matchingIds(MciCaseQueryParametersDTO $query): array
    {
        $ids = [];
        foreach ($this->repository()->getListPaginator($query)->getResults() as $row) {
            /* @var array{mciId: string} $row */
            $ids[] = $row['mciId'];
        }
        sort($ids);

        return $ids;
    }

    private function repository(): MciCaseRepository
    {
        $repository = self::getContainer()->get(MciCaseRepository::class);
        self::assertInstanceOf(MciCaseRepository::class, $repository);

        return $repository;
    }

    private function createCase(
        string $mciId,
        mixed $state = null,
        ?AllocationUrgency $urgency = null,
        ?AllocationTransportType $transportType = null,
        mixed $department = null,
        mixed $speciality = null,
        ?bool $departmentWasClosed = null,
        ?bool $requiresResus = null,
        ?bool $isWithPhysician = null,
        mixed $indication = null,
        mixed $occasion = null,
        mixed $infection = null,
    ): void {
        $user = UserFactory::createOne();
        $state ??= StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $user,
            'createdBy' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);

        MciCaseFactory::createOne([
            'mciId' => $mciId,
            'mciTitle' => $mciId,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'hospital' => $hospital,
            'import' => $import,
            'urgency' => $urgency,
            'transportType' => $transportType,
            'department' => $department,
            'speciality' => $speciality,
            'departmentWasClosed' => $departmentWasClosed,
            'requiresResus' => $requiresResus,
            'isWithPhysician' => $isWithPhysician,
            'indicationNormalized' => $indication,
            'occasion' => $occasion,
            'infection' => $infection,
            'indicationRaw' => null,
        ]);
    }
}
