<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\CaseFlow;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowOriginDistributionQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowRegionalMetricsQuery;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class CaseFlowRegionalMetricsQueryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAggregatesRegionalAndEmergencyMetricsForHospitalScope(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'case-flow-metrics-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'CaseFlowMetricsState']);
        $originArea = DispatchAreaFactory::createOne(['name' => 'CaseFlowOriginArea', 'state' => $state]);
        $otherArea = DispatchAreaFactory::createOne(['name' => 'CaseFlowOtherArea', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CaseFlowMetricsHospital',
            'state' => $state,
            'dispatchArea' => $originArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'CaseFlowMetricsSpec']);
        DepartmentFactory::createOne(['name' => 'CaseFlowMetricsDept']);
        AssignmentFactory::createOne(['name' => 'CaseFlowMetricsAssign']);
        IndicationRawFactory::createOne(['name' => 'CaseFlowMetricsRaw', 'code' => 912_501]);

        $import = ImportFactory::createOne(['name' => 'CaseFlowMetricsImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(7, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $originArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-03-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-01 08:30:00'),
        ]);

        AllocationFactory::createMany(3, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $otherArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2026-03-02 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-02 10:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $scope = new StatisticsScopeCriteria([$hospital->getId()]);
        $metricsQuery = self::getContainer()->get(CaseFlowRegionalMetricsQuery::class);
        $originQuery = self::getContainer()->get(CaseFlowOriginDistributionQuery::class);

        $metrics = $metricsQuery->fetch(null, null, $scope);
        $origins = $originQuery->fetch(null, null, $scope);

        self::assertSame(10, $metrics->totalCases);
        self::assertSame(7, $metrics->regionalCases);
        self::assertSame(10, $metrics->fullTierCases);
        self::assertSame(7, $metrics->emergencyCases);
        self::assertNotNull($metrics->meanTransportMinutes);
        self::assertNotNull($metrics->medianTransportMinutes);

        self::assertCount(2, $origins);
        self::assertSame('CaseFlowOriginArea', $origins[0]->originName);
        self::assertSame(7, $origins[0]->caseCount);
        self::assertSame(7, $origins[0]->emergencyCount);
        self::assertSame('CaseFlowOtherArea', $origins[1]->originName);
        self::assertSame(3, $origins[1]->caseCount);
    }

    public function testReturnsZerosForEmptyHospitalScope(): void
    {
        self::bootKernel();

        $metrics = self::getContainer()->get(CaseFlowRegionalMetricsQuery::class)
            ->fetch(null, null, new StatisticsScopeCriteria([]));

        self::assertSame(0, $metrics->totalCases);
        self::assertSame(0, $metrics->regionalCases);
        self::assertNull($metrics->meanTransportMinutes);

        $origins = self::getContainer()->get(CaseFlowOriginDistributionQuery::class)
            ->fetch(null, null, new StatisticsScopeCriteria([]));

        self::assertSame([], $origins);
    }
}
