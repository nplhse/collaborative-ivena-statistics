<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
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
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ProjectionTimeSeriesCreatedYearQueryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testCountGroupedByCreatedYearMatchesCountByYearInPeriodForCalendarYearStart(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'created-year-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'CreatedYearState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CreatedYearDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CreatedYearHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'CreatedYearSpec']);
        DepartmentFactory::createOne(['name' => 'CreatedYearDept']);
        AssignmentFactory::createOne(['name' => 'CreatedYearAssign']);
        OccasionFactory::createOne(['name' => 'CreatedYearOcc']);
        SecondaryTransportFactory::createOne(['name' => 'CreatedYearSec']);
        InfectionFactory::createOne(['name' => 'CreatedYearInf']);
        IndicationRawFactory::createOne(['name' => 'CreatedYearRaw', 'code' => 912_351]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'CreatedYearNorm']);

        $import = ImportFactory::createOne(['name' => 'CreatedYearImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'age' => 40,
            'requiresResus' => false,
            'requiresCathlab' => false,
            'isCPR' => false,
            'isVentilated' => false,
            'isWorkAccident' => false,
            'isWithPhysician' => false,
            'occasion' => null,
            'infection' => null,
            'indicationNormalized' => $indicationNormalized,
            'createdAt' => new \DateTimeImmutable('2025-04-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-04-01 08:30:00'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($import->getId());

        $query = self::getContainer()->get(ProjectionTimeSeriesQuery::class);
        $startYear = 2025;
        $fromDate = new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $startYear));

        $byYearColumn = $query->countGroupedByCreatedYear($startYear, null, null);
        $byCreatedAt = $query->countByYearInPeriod($fromDate, null, null);

        self::assertSame($byCreatedAt, $byYearColumn);
        self::assertSame($query->countBefore($fromDate), $query->countWithCreatedYearBefore($startYear));
    }
}
