<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query;

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
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsTransportTypeProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Infrastructure\Entity\AllocationStatsProjection;
use App\Statistics\Infrastructure\Repository\AllocationStatsProjectionRepository;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AllocationStatsProjectionRebuilderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testRebuildForImportWritesExpectedProjectionRow(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'stats-proj-'.bin2hex(random_bytes(5))]);
        $state = StateFactory::createOne(['name' => 'StatsProjState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'StatsProjDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'StatsProjHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);
        $import = ImportFactory::createOne([
            'name' => 'StatsProjImport',
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);

        SpecialityFactory::createOne(['name' => 'StatsProjSpeciality']);
        DepartmentFactory::createOne(['name' => 'StatsProjDepartment']);
        AssignmentFactory::createOne(['name' => 'StatsProjAssignment']);
        OccasionFactory::createOne(['name' => 'StatsProjOccasion']);
        SecondaryTransportFactory::createOne(['name' => 'StatsProjSecondary']);
        InfectionFactory::createOne(['name' => 'StatsProjInfection']);
        IndicationRawFactory::createOne(['name' => 'StatsProjIndRaw', 'code' => 912_345]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'StatsProjIndNorm']);

        $createdAt = new \DateTimeImmutable('2025-06-15 10:00:00');
        $arrivalAt = new \DateTimeImmutable('2025-06-15 11:30:00');

        $allocation = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'createdAt' => $createdAt,
            'arrivalAt' => $arrivalAt,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'age' => 42,
            'requiresResus' => true,
            'requiresCathlab' => false,
            'isCPR' => false,
            'isVentilated' => true,
            'isWithPhysician' => false,
            'occasion' => null,
            'infection' => null,
            'indicationNormalized' => $indicationNormalized,
        ]);

        $importId = $import->getId();
        $allocationId = $allocation->getId();

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importId);

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $row = $connection->fetchAssociative(
            'SELECT * FROM allocation_stats_projection WHERE id = :id',
            ['id' => $allocationId]
        );

        self::assertIsArray($row, 'Projection row should exist for allocation id '.$allocationId);

        self::assertSame($allocationId, (int) $row['id']);
        self::assertSame($importId, (int) $row['import_id']);
        self::assertSame($hospital->getId(), (int) $row['hospital_id']);
        self::assertSame($state->getId(), (int) $row['state_id']);
        self::assertSame($dispatchArea->getId(), (int) $row['dispatch_area_id']);

        self::assertNull($row['occasion_id'] ?? null);
        self::assertNull($row['infection_id'] ?? null);
        self::assertSame($indicationNormalized->getId(), (int) $row['indication_normalized_id']);

        self::assertSame(90, (int) $row['transport_time_minutes']);
        self::assertSame(2025, (int) $row['created_year']);
        self::assertSame(2, (int) $row['created_quarter']);
        self::assertSame(6, (int) $row['created_month']);
        self::assertSame((int) $createdAt->format('W'), (int) $row['created_week']);
        self::assertSame(15, (int) $row['created_day']);
        self::assertSame((int) $createdAt->format('N'), (int) $row['created_weekday']);
        self::assertSame(10, (int) $row['created_hour']);

        self::assertSame(42, (int) $row['age']);
        self::assertSame(AllocationStatsGenderProjectionCode::Male->value, (int) $row['gender_code']);
        self::assertSame(AllocationStatsUrgencyProjectionCode::Emergency->value, (int) $row['urgency_code']);
        self::assertSame(AllocationStatsTransportTypeProjectionCode::Ground->value, (int) $row['transport_type_code']);

        self::assertTrue($this->toBool($row['requires_resus']));
        self::assertFalse($this->toBool($row['requires_cathlab']));
        self::assertFalse($this->toBool($row['is_cpr']));
        self::assertTrue($this->toBool($row['is_ventilated']));
        self::assertFalse($this->toBool($row['is_with_physician']));

        $repo = self::getContainer()->get(AllocationStatsProjectionRepository::class);
        $projection = $repo->find($allocationId);
        self::assertInstanceOf(AllocationStatsProjection::class, $projection);

        self::assertSame($allocationId, $projection->getId());
        self::assertSame($importId, $projection->getImportId());
        self::assertSame($hospital->getId(), $projection->getHospitalId());
        self::assertSame($state->getId(), $projection->getStateId());
        self::assertSame($dispatchArea->getId(), $projection->getDispatchAreaId());
        self::assertSame($allocation->getSpeciality()->getId(), $projection->getSpecialityId());
        self::assertSame($allocation->getDepartment()->getId(), $projection->getDepartmentId());
        self::assertNull($projection->getOccasionId());
        self::assertSame($allocation->getAssignment()->getId(), $projection->getAssignmentId());
        self::assertNull($projection->getInfectionId());
        self::assertSame($indicationNormalized->getId(), $projection->getIndicationNormalizedId());

        self::assertEquals($createdAt, $projection->getCreatedAt());
        self::assertEquals($arrivalAt, $projection->getArrivalAt());
        self::assertSame(2025, $projection->getCreatedYear());
        self::assertSame(2, $projection->getCreatedQuarter());
        self::assertSame(6, $projection->getCreatedMonth());
        self::assertSame((int) $createdAt->format('W'), $projection->getCreatedWeek());
        self::assertSame(15, $projection->getCreatedDay());
        self::assertSame((int) $createdAt->format('N'), $projection->getCreatedWeekday());
        self::assertSame(10, $projection->getCreatedHour());
        self::assertSame(90, $projection->getTransportTimeMinutes());

        self::assertSame(42, $projection->getAge());
        self::assertSame(AllocationStatsGenderProjectionCode::Male->value, $projection->getGenderCode());
        self::assertSame(AllocationStatsUrgencyProjectionCode::Emergency->value, $projection->getUrgencyCode());
        self::assertSame(AllocationStatsTransportTypeProjectionCode::Ground->value, $projection->getTransportTypeCode());

        self::assertTrue($projection->isRequiresResus());
        self::assertFalse($projection->isRequiresCathlab());
        self::assertFalse($projection->isCpr());
        self::assertTrue($projection->isVentilated());
        self::assertFalse($projection->isWithPhysician());
    }

    public function testRebuildTwiceKeepsStableRowCountPerImport(): void
    {
        self::bootKernel();

        $d = $this->seedReferenceGraph();

        $base = [
            'import' => $d['import'],
            'hospital' => $d['hospital'],
            'state' => $d['state'],
            'dispatchArea' => $d['dispatchArea'],
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'transportType' => AllocationTransportType::AIR,
            'age' => 55,
            'requiresResus' => false,
            'requiresCathlab' => true,
            'isCPR' => false,
            'isVentilated' => false,
            'isWithPhysician' => true,
            'occasion' => null,
            'infection' => null,
            'indicationNormalized' => $d['indicationNormalized'],
            'createdAt' => new \DateTimeImmutable('2025-03-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-03-01 08:45:00'),
        ];

        AllocationFactory::createOne($base);
        AllocationFactory::createOne(array_merge($base, [
            'createdAt' => new \DateTimeImmutable('2025-03-02 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-03-02 10:00:00'),
            'age' => 60,
        ]));

        $importId = $d['import']->getId();
        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);

        $rebuilder->rebuildForImport($importId);
        $rebuilder->rebuildForImport($importId);

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM allocation_stats_projection WHERE import_id = :i',
            ['i' => $importId]
        );

        self::assertSame(2, $count);
    }

    /**
     * @return array{
     *     user: object,
     *     state: object,
     *     dispatchArea: object,
     *     hospital: object,
     *     import: object,
     *     indicationNormalized: object
     * }
     */
    private function seedReferenceGraph(): array
    {
        $user = UserFactory::createOne(['username' => 'stats-proj-seed-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'StatsProjSeedState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'StatsProjSeedDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'StatsProjSeedHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);
        $import = ImportFactory::createOne([
            'name' => 'StatsProjSeedImport',
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);

        SpecialityFactory::createOne(['name' => 'StatsProjSeedSpec']);
        DepartmentFactory::createOne(['name' => 'StatsProjSeedDept']);
        AssignmentFactory::createOne(['name' => 'StatsProjSeedAssign']);
        OccasionFactory::createOne(['name' => 'StatsProjSeedOcc']);
        SecondaryTransportFactory::createOne(['name' => 'StatsProjSeedSec']);
        InfectionFactory::createOne(['name' => 'StatsProjSeedInf']);
        IndicationRawFactory::createOne(['name' => 'StatsProjSeedRaw', 'code' => 912_346]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'StatsProjSeedNorm']);

        return [
            'user' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'hospital' => $hospital,
            'import' => $import,
            'indicationNormalized' => $indicationNormalized,
        ];
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return 0 !== (int) $value;
        }

        $v = strtolower(trim((string) $value));

        return match ($v) {
            '1', 't', 'true', 'yes' => true,
            default => false,
        };
    }
}
