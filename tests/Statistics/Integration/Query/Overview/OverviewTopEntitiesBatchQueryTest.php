<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\Overview;

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
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Infrastructure\Query\Overview\OverviewQueryCriteria;
use App\Statistics\Infrastructure\Query\Overview\OverviewTopEntitiesBatchQuery;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class OverviewTopEntitiesBatchQueryTest extends KernelTestCase
{
    use Factories;

    public function testReturnsTopRowsForAllDimensionsInOneQuery(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'top-batch-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'TopBatchState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'TopBatchDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'TopBatchHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        $speciality = SpecialityFactory::createOne(['name' => 'TopBatchSpec']);
        $department = DepartmentFactory::createOne(['name' => 'TopBatchDept']);
        $assignment = AssignmentFactory::createOne(['name' => 'TopBatchAssign']);
        $occasion = OccasionFactory::createOne(['name' => 'TopBatchOcc']);
        $infection = InfectionFactory::createOne(['name' => 'TopBatchInf']);
        $secondary = IndicationNormalizedFactory::createOne(['name' => 'TopBatchSecondary']);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'TopBatchPrimary']);
        IndicationRawFactory::createOne(['name' => 'TopBatchRaw', 'code' => 912_351]);

        $import = ImportFactory::createOne(['name' => 'TopBatchImport', 'hospital' => $hospital, 'createdBy' => $user]);

        $allocationDefaults = [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'speciality' => $speciality,
            'department' => $department,
            'assignment' => $assignment,
            'occasion' => $occasion,
            'infection' => $infection,
            'indicationNormalized' => $indication,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'transportType' => AllocationTransportType::GROUND,
            'createdAt' => new \DateTimeImmutable('2025-06-15 10:00:00'),
        ];
        AllocationFactory::createOne(array_merge($allocationDefaults, ['secondaryIndicationNormalized' => $secondary]));
        AllocationFactory::createOne(array_merge($allocationDefaults, ['infection' => null]));

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospital->getId(),
            null,
            StatisticsFilterPeriod::Month,
            2025,
            6,
        );
        $bounds = StatisticsPeriodResolver::resolve($filter);
        $criteria = OverviewQueryCriteria::fromPeriodBounds($bounds, [$hospital->getId()]);

        $batch = self::getContainer()->get(OverviewTopEntitiesBatchQuery::class);
        $result = $batch($criteria, 5);

        self::assertSame('TopBatchSpec', $result[OverviewTopEntitiesBatchQuery::DIMENSION_SPECIALITY][0]['label']);
        self::assertSame(2, $result[OverviewTopEntitiesBatchQuery::DIMENSION_SPECIALITY][0]['count']);
        self::assertSame('TopBatchDept', $result[OverviewTopEntitiesBatchQuery::DIMENSION_DEPARTMENT][0]['label']);
        self::assertSame('TopBatchAssign', $result[OverviewTopEntitiesBatchQuery::DIMENSION_ASSIGNMENT][0]['label']);
        self::assertSame('TopBatchOcc', $result[OverviewTopEntitiesBatchQuery::DIMENSION_OCCASION][0]['label']);
        self::assertSame('TopBatchInf', $result[OverviewTopEntitiesBatchQuery::DIMENSION_INFECTION][0]['label']);
        self::assertSame(1, $result[OverviewTopEntitiesBatchQuery::DIMENSION_INFECTION][0]['count']);
        $infectionLabels = array_column($result[OverviewTopEntitiesBatchQuery::DIMENSION_INFECTION], 'label');
        self::assertNotContains('Unknown', $infectionLabels);
        self::assertContains(OverviewTopEntitiesBatchQuery::PRESENCE_TOTAL_LABEL, $infectionLabels);
        self::assertSame(1, $this->presenceTotal($result[OverviewTopEntitiesBatchQuery::DIMENSION_INFECTION]));
        self::assertSame('TopBatchSecondary', $result[OverviewTopEntitiesBatchQuery::DIMENSION_SECONDARY_DIAGNOSIS][0]['label']);
        self::assertSame(1, $result[OverviewTopEntitiesBatchQuery::DIMENSION_SECONDARY_DIAGNOSIS][0]['count']);
        $secondaryLabels = array_column($result[OverviewTopEntitiesBatchQuery::DIMENSION_SECONDARY_DIAGNOSIS], 'label');
        self::assertNotContains('Unknown', $secondaryLabels);
        self::assertContains(OverviewTopEntitiesBatchQuery::PRESENCE_TOTAL_LABEL, $secondaryLabels);
        self::assertSame(1, $this->presenceTotal($result[OverviewTopEntitiesBatchQuery::DIMENSION_SECONDARY_DIAGNOSIS]));
    }

    /**
     * @param list<array{label: string, count: int}> $rows
     */
    private function presenceTotal(array $rows): ?int
    {
        foreach ($rows as $row) {
            if (OverviewTopEntitiesBatchQuery::PRESENCE_TOTAL_LABEL === $row['label']) {
                return $row['count'];
            }
        }

        return null;
    }
}
