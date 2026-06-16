<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\Overview;

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
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Infrastructure\Query\Overview\OverviewQueryCriteria;
use App\Statistics\Infrastructure\Query\ProjectionFeatureQuery;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class GetOverviewSummaryQueryTest extends KernelTestCase
{
    use Factories;

    public function testSummaryMatchesLegacyQueriesForPeriodAndScope(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'overview-summary-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'OverviewSummaryState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'OverviewSummaryDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'OverviewSummaryHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'OverviewSummarySpec']);
        DepartmentFactory::createOne(['name' => 'OverviewSummaryDept']);
        AssignmentFactory::createOne(['name' => 'OverviewSummaryAssign']);
        OccasionFactory::createOne(['name' => 'OverviewSummaryOcc']);
        SecondaryTransportFactory::createOne(['name' => 'OverviewSummarySec']);
        InfectionFactory::createOne(['name' => 'OverviewSummaryInf']);
        IndicationRawFactory::createOne(['name' => 'OverviewSummaryRaw', 'code' => 912_348]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'OverviewSummaryNorm']);

        $import = ImportFactory::createOne(['name' => 'OverviewSummaryImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'age' => 40,
            'requiresResus' => true,
            'requiresCathlab' => false,
            'isCPR' => true,
            'isVentilated' => false,
            'isWorkAccident' => false,
            'isWithPhysician' => true,
            'occasion' => null,
            'infection' => null,
            'indicationNormalized' => $indicationNormalized,
            'createdAt' => new \DateTimeImmutable('2025-04-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-04-01 08:30:00'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($import->getId());

        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospital->getId(),
            null,
            StatisticsFilterPeriod::All,
        );
        $bounds = StatisticsPeriodResolver::resolve($filter);
        $criteria = OverviewQueryCriteria::fromPeriodBounds($bounds, [$hospital->getId()]);

        $container = self::getContainer();
        $legacy = new OverviewLegacyQueryFactory(
            $container->get(Connection::class),
            $container->get(ProjectionFeatureQuery::class),
        );
        $summary = $legacy->summary()($criteria);

        $timeSeries = self::getContainer()->get(ProjectionTimeSeriesQuery::class);
        $features = self::getContainer()->get(ProjectionFeatureQuery::class);

        self::assertSame(
            $timeSeries->countCreatedInPeriod($bounds->from, $bounds->toExclusive, [$hospital->getId()]),
            $summary->total,
        );

        $clinical = $features->clinicalFeatureCounts($bounds->from, $bounds->toExclusive, [$hospital->getId()]);
        self::assertSame($clinical['with_physician'], $summary->withPhysician);
        self::assertSame($clinical['cpr'], $summary->cpr);
        self::assertSame($clinical['ventilated'], $summary->ventilated);
        self::assertSame($clinical['infectious'], $summary->infectious);

        $resources = $features->resourceFeatureCounts($bounds->from, $bounds->toExclusive, [$hospital->getId()]);
        self::assertSame($resources['cathlab'], $summary->cathlab);
        self::assertSame($resources['resus'], $summary->resus);
    }

    public function testAllTimeCriteriaOmitsCreatedAtLowerBound(): void
    {
        self::bootKernel();

        $filter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::AllTime,
        );
        $bounds = StatisticsPeriodResolver::resolve($filter);
        self::assertNull($bounds->from);

        $criteria = OverviewQueryCriteria::fromPeriodBounds($bounds, null);
        $container = self::getContainer();
        $legacy = new OverviewLegacyQueryFactory(
            $container->get(Connection::class),
            $container->get(ProjectionFeatureQuery::class),
        );
        $summary = $legacy->summary()($criteria);

        self::assertGreaterThanOrEqual(0, $summary->total);
    }
}
