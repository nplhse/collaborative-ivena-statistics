<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application\Overview;

use App\Allocation\Domain\Enum\AllocationGender;
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
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\Overview\OverviewPeriodComparisonService;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class OverviewPeriodComparisonServiceIntegrationTest extends KernelTestCase
{
    use Factories;

    public function testFetchPreviousScopedTotalReturnsCountForPreviousMonth(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'overview-pop-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'OverviewPopState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'OverviewPopDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'OverviewPopHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'OverviewPopSpec']);
        DepartmentFactory::createOne(['name' => 'OverviewPopDept']);
        AssignmentFactory::createOne(['name' => 'OverviewPopAssign']);
        IndicationRawFactory::createOne(['name' => 'OverviewPopRaw', 'code' => 912_360]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'OverviewPopNorm']);

        $import = ImportFactory::createOne(['name' => 'OverviewPopImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(12, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'indicationNormalized' => $indicationNormalized,
            'createdAt' => new \DateTimeImmutable('2025-05-10 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-05-10 10:20:00'),
        ]);
        AllocationFactory::createMany(18, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'indicationNormalized' => $indicationNormalized,
            'createdAt' => new \DateTimeImmutable('2025-06-10 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-10 10:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $context = new StatisticsContext(
            $user,
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::Month,
                2025,
                6,
            ),
        );

        $service = self::getContainer()->get(OverviewPeriodComparisonService::class);

        self::assertSame(12, $service->fetchPreviousScopedTotal($context));
    }

    public function testFetchPreviousScopedTotalReturnsNullForNonComparablePeriod(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'overview-pop-all-'.bin2hex(random_bytes(4))]);
        $context = new StatisticsContext(
            $user,
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::All,
            ),
        );

        $service = self::getContainer()->get(OverviewPeriodComparisonService::class);

        self::assertNull($service->fetchPreviousScopedTotal($context));
    }
}
