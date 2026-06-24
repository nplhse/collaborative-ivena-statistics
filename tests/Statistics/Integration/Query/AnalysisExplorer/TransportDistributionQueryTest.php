<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\AnalysisExplorer;

use App\Allocation\Domain\Enum\AllocationUrgency;
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
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerAllocationAnalysisExecutor;
use App\Statistics\AnalysisExplorer\Application\ExplorerHospitalAnalysisExecutor;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TransportDistributionQueryTest extends KernelTestCase
{
    use Factories;

    public function testHospitalTransportTimeDistributionQuery(): void
    {
        self::bootKernel();
        $import = $this->seedAllocationWithTransportTime();
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $executor = self::getContainer()->get(ExplorerHospitalAnalysisExecutor::class);
        $rows = $executor->execute(new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::TransportTimePerHospitalDistribution],
            visualMetricKey: AnalysisMetricKey::TransportTimePerHospitalDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: null,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            hospitalPopulationMode: ExplorerHospitalPopulationMode::Participating,
        ));

        self::assertNotEmpty($rows);
    }

    public function testAllocationTransportTimeDistributionQuery(): void
    {
        self::bootKernel();
        $import = $this->seedAllocationWithTransportTime();
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $executor = self::getContainer()->get(ExplorerAllocationAnalysisExecutor::class);
        $rows = $executor->execute(new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::TransportTimeDistribution],
            visualMetricKey: AnalysisMetricKey::TransportTimeDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
            columnAxis: null,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertNotEmpty($rows);
    }

    public function testAllocationTransportTimeDistributionWithColumnAxis(): void
    {
        self::bootKernel();
        $import = $this->seedAllocationWithTransportTime();
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $executor = self::getContainer()->get(ExplorerAllocationAnalysisExecutor::class);
        $rows = $executor->execute(new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::TransportTimeDistribution],
            visualMetricKey: AnalysisMetricKey::TransportTimeDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertNotEmpty($rows);
        self::assertNotNull($rows[0]->seriesKey);
    }

    public function testHospitalBedsDistributionCompareMode(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne();
        $state = StateFactory::createOne(['createdBy' => $user]);
        $area = DispatchAreaFactory::createOne(['state' => $state]);
        HospitalFactory::createOne([
            'tier' => HospitalTier::FULL,
            'isParticipating' => true,
            'beds' => 100,
            'state' => $state,
            'dispatchArea' => $area,
        ]);
        HospitalFactory::createOne([
            'tier' => HospitalTier::BASIC,
            'isParticipating' => false,
            'beds' => 40,
            'state' => $state,
            'dispatchArea' => $area,
        ]);

        $executor = self::getContainer()->get(ExplorerHospitalAnalysisExecutor::class);
        $rows = $executor->execute(new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::BedsDistribution],
            visualMetricKey: AnalysisMetricKey::BedsDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: null,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            hospitalPopulationMode: ExplorerHospitalPopulationMode::Compare,
        ));

        self::assertNotEmpty($rows);
        self::assertNotNull($rows[0]->seriesKey);
    }

    private function seedAllocationWithTransportTime(): object
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne(['createdBy' => $user]);
        $area = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'tier' => HospitalTier::FULL,
            'isParticipating' => true,
            'state' => $state,
            'dispatchArea' => $area,
        ]);
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        OccasionFactory::createOne();
        InfectionFactory::createOne();
        IndicationRawFactory::createOne();
        IndicationNormalizedFactory::createOne();
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $area,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-01-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-01-01 08:30:00'),
        ]);

        return $import;
    }
}
