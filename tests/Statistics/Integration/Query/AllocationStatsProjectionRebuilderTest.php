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
