<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application\Projection;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\CaseId\CaseIdHasher;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Projection\AllocationProjectionDeduplicator;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AllocationProjectionDeduplicatorTest extends KernelTestCase
{
    use Factories;

    public function testExecuteForHospitalRemovesDuplicatesOnlyInScopedHospital(): void
    {
        self::bootKernel();

        $seedA = $this->seedReferenceGraph();
        $seedB = $this->seedReferenceGraph();

        /** @var CaseIdHasher $hasher */
        $hasher = self::getContainer()->get(CaseIdHasher::class);
        $caseIdHash = $hasher->hashFrom('998877665');

        $this->seedDuplicatePair($seedA, $caseIdHash);
        $otherHospitalDuplicate = $this->seedDuplicatePair($seedB, $hasher->hashFrom('112233445'));

        /** @var AllocationProjectionDeduplicator $deduplicator */
        $deduplicator = self::getContainer()->get(AllocationProjectionDeduplicator::class);
        $hospitalId = (int) $seedA['hospital']->getId();

        $result = $deduplicator->executeForHospital($hospitalId, (int) $otherHospitalDuplicate['newerImportId']);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(1, $result->totalDeletedAllocations());
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM allocation WHERE hospital_id = :hospitalId',
            ['hospitalId' => $hospitalId],
        ));
        self::assertSame(2, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM allocation WHERE hospital_id = :hospitalId',
            ['hospitalId' => (int) $seedB['hospital']->getId()],
        ));
    }

    public function testExecuteForHospitalAttributesDeletionsToTriggeringImport(): void
    {
        self::bootKernel();

        $seed = $this->seedReferenceGraph();
        /** @var CaseIdHasher $hasher */
        $hasher = self::getContainer()->get(CaseIdHasher::class);
        $fixture = $this->seedDuplicatePair($seed, $hasher->hashFrom('554433221'));

        /** @var AllocationProjectionDeduplicator $deduplicator */
        $deduplicator = self::getContainer()->get(AllocationProjectionDeduplicator::class);

        $result = $deduplicator->executeForHospital(
            (int) $seed['hospital']->getId(),
            $fixture['newerImportId'],
        );

        self::assertSame(1, $result->totalDeletedAllocations());
        self::assertSame(0, $result->deletedFromCurrentImport);
        self::assertSame(1, $result->deletedFromOtherImports);
    }

    /**
     * @param array{
     *     user: object,
     *     state: object,
     *     dispatchArea: object,
     *     hospital: object,
     *     indicationNormalized: object,
     *     indicationRaw: object,
     *     speciality: object,
     *     department: object,
     *     assignment: object
     * } $seed
     *
     * @return array{newerImportId: int, olderImportId: int}
     */
    private function seedDuplicatePair(array $seed, string $caseIdHash): array
    {
        $olderImport = ImportFactory::createOne([
            'name' => 'Dedup Unit Old '.bin2hex(random_bytes(3)),
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'createdAt' => new \DateTimeImmutable('2024-01-01 10:00:00'),
        ]);
        $newerImport = ImportFactory::createOne([
            'name' => 'Dedup Unit New '.bin2hex(random_bytes(3)),
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'createdAt' => new \DateTimeImmutable('2025-06-01 10:00:00'),
        ]);

        $shared = $this->allocationDefaults($seed, $olderImport, 30, '2025-03-01 08:00:00', '2025-03-01 08:30:00');
        $shared['caseIdHash'] = $caseIdHash;
        AllocationFactory::createOne($shared);

        $newerDefaults = $this->allocationDefaults($seed, $newerImport, 30, '2025-03-01 08:00:00', '2025-03-01 08:30:00');
        $newerDefaults['caseIdHash'] = $caseIdHash;
        AllocationFactory::createOne($newerDefaults);

        return [
            'newerImportId' => (int) $newerImport->getId(),
            'olderImportId' => (int) $olderImport->getId(),
        ];
    }

    /**
     * @return array{
     *     user: object,
     *     state: object,
     *     dispatchArea: object,
     *     hospital: object,
     *     indicationNormalized: object,
     *     indicationRaw: object,
     *     speciality: object,
     *     department: object,
     *     assignment: object
     * }
     */
    private function seedReferenceGraph(): array
    {
        $user = UserFactory::createOne(['username' => 'dedup-unit-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'DedupUnitState-'.bin2hex(random_bytes(3))]);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'DedupUnitDispatch-'.bin2hex(random_bytes(3)), 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'DedupUnitHospital-'.bin2hex(random_bytes(3)),
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        $speciality = SpecialityFactory::createOne(['name' => 'DedupUnitSpeciality-'.bin2hex(random_bytes(3))]);
        $department = DepartmentFactory::createOne(['name' => 'DedupUnitDepartment-'.bin2hex(random_bytes(3))]);
        $assignment = AssignmentFactory::createOne(['name' => 'DedupUnitAssignment-'.bin2hex(random_bytes(3))]);
        OccasionFactory::createOne(['name' => 'DedupUnitOccasion-'.bin2hex(random_bytes(3))]);
        $indicationRaw = IndicationRawFactory::createOne(['name' => 'DedupUnitRawIndication', 'code' => 800001]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'DedupUnitNormalizedIndication']);

        return [
            'user' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'hospital' => $hospital,
            'speciality' => $speciality,
            'department' => $department,
            'assignment' => $assignment,
            'indicationNormalized' => $indicationNormalized,
            'indicationRaw' => $indicationRaw,
        ];
    }

    /**
     * @param array{
     *     user: object,
     *     state: object,
     *     dispatchArea: object,
     *     hospital: object,
     *     indicationNormalized: object,
     *     indicationRaw: object,
     *     speciality: object,
     *     department: object,
     *     assignment: object
     * } $seed
     *
     * @return array<string, mixed>
     */
    private function allocationDefaults(
        array $seed,
        object $import,
        int $age,
        string $createdAt,
        string $arrivalAt,
    ): array {
        return [
            'import' => $import,
            'hospital' => $seed['hospital'],
            'state' => $seed['state'],
            'dispatchArea' => $seed['dispatchArea'],
            'speciality' => $seed['speciality'],
            'department' => $seed['department'],
            'assignment' => $seed['assignment'],
            'indicationRaw' => $seed['indicationRaw'],
            'indicationNormalized' => $seed['indicationNormalized'],
            'createdAt' => new \DateTimeImmutable($createdAt),
            'arrivalAt' => new \DateTimeImmutable($arrivalAt),
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'age' => $age,
            'requiresResus' => false,
            'requiresCathlab' => false,
            'isCPR' => false,
            'isVentilated' => false,
            'isWorkAccident' => false,
            'isWithPhysician' => false,
            'isShock' => false,
            'isPregnant' => false,
            'occasion' => null,
            'infection' => null,
            'departmentWasClosed' => false,
        ];
    }
}
