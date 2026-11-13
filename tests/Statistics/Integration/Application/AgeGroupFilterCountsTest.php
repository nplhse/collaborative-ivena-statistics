<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application;

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
use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\StatisticsAgeGroupFilter;
use App\Statistics\Application\TopList\TopDiagnosesReport;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisQuery;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Fixture: three allocations of one hospital and one indication with ages 17, 18 and none (#181).
 * Imports reject rows without age, so the third case gets its projection age cleared after the rebuild
 * (`allocation_stats_projection.age` is nullable).
 *
 * Expected by hand: all cases = 3, children & adolescents (< 18) = 1 (age 17),
 * adults (>= 18) = 1 (age 18). The case without age only counts without an age group.
 * The age buckets put 18-year-olds with adults (#1040): 0–17 = 1 (age 17), 18–29 = 1 (age 18).
 * The pre-#1040 keys 0_18 and 19_29 are read as 0_17 and 18_29, so they give the same counts.
 */
#[ResetDatabase]
final class AgeGroupFilterCountsTest extends KernelTestCase
{
    use Factories;

    /**
     * @return iterable<string, array{?string, int}>
     */
    public static function ageGroupProvider(): iterable
    {
        yield 'all cases' => [null, 3];
        yield 'children and adolescents' => [StatisticsAgeGroupFilter::UNDER_18, 1];
        yield 'adults' => [StatisticsAgeGroupFilter::ADULTS, 1];
        yield '0-17' => ['0_17', 1];
        yield '18-29' => ['18_29', 1];
        yield 'legacy 0-18 read as 0-17' => ['0_18', 1];
        yield 'legacy 19-29 read as 18-29' => ['19_29', 1];
    }

    #[DataProvider('ageGroupProvider')]
    public function testTopListCountsAndShareDenominatorFollowAgeGroup(?string $ageGroup, int $expectedCount): void
    {
        [$user, $hospitalId] = $this->createFixture();

        $report = self::getContainer()->get(TopDiagnosesReport::class);
        $context = new StatisticsContext(
            $user,
            new StatisticsFilter(StatisticsFilterScope::Hospital, $hospitalId, null, StatisticsFilterPeriod::AllTime),
            drawerFilter: new StatisticsDrawerFilter(ageGroup: $ageGroup),
        );

        $rows = $report->build($context, 25)->payload['rows'];

        self::assertCount(1, $rows);
        self::assertSame('Age Filter Indication', $rows[0][1]);
        self::assertSame((string) $expectedCount, $rows[0][2]);
        // The share denominator is filtered too: the only indication is always 100 %.
        self::assertSame('100.0%', $rows[0][3]);
    }

    #[DataProvider('ageGroupProvider')]
    public function testExplorerGrandTotalFollowsAgeGroup(?string $ageGroup, int $expectedCount): void
    {
        [, $hospitalId] = $this->createFixture();

        $result = self::getContainer()->get(GenericAllocationAnalysisQuery::class)->execute(new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: new StatisticsScopeCriteria([$hospitalId]),
            periodBounds: new StatisticsPeriodBounds(null),
            filters: null === $ageGroup ? [] : [new AnalysisFilter('age_group', AnalysisFilterOperator::Equals, $ageGroup)],
        ));

        self::assertSame($expectedCount, $result->grandTotal);
    }

    public function testExplorerAgeGroupBucketsCountEighteenYearOldsAsAdults(): void
    {
        [, $hospitalId] = $this->createFixture();

        $result = self::getContainer()->get(GenericAllocationAnalysisQuery::class)->execute(new AnalysisQuery(
            primaryDimensionKey: 'age_group',
            scopeCriteria: new StatisticsScopeCriteria([$hospitalId]),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        $counts = [];
        foreach ($result->rows as $row) {
            if ($row->countValue() > 0) {
                $counts[(string) $row->bucket] = $row->countValue();
            }
        }

        self::assertSame(['0_17' => 1, '18_29' => 1], $counts);
    }

    /**
     * @return array{User, int}
     */
    private function createFixture(): array
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'age-group-filter-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'AgeGroupFilterState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'AgeGroupFilterDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'AgeGroupFilterHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        SpecialityFactory::createOne(['name' => 'AgeGroupFilterSpec']);
        DepartmentFactory::createOne(['name' => 'AgeGroupFilterDept']);
        AssignmentFactory::createOne(['name' => 'AgeGroupFilterAssign']);
        IndicationRawFactory::createOne(['name' => 'AgeGroupFilterRaw', 'code' => 918_101]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Age Filter Indication']);

        $import = ImportFactory::createOne(['name' => 'AgeGroupFilterImport', 'hospital' => $hospital, 'createdBy' => $user]);
        foreach ([17, 18, 50] as $index => $age) {
            AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'indicationNormalized' => $indication,
                'age' => $age,
                'createdAt' => new \DateTimeImmutable(sprintf('2026-04-01 1%d:00:00', $index)),
                'arrivalAt' => new \DateTimeImmutable(sprintf('2026-04-01 1%d:20:00', $index)),
            ]);
        }

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE allocation_stats_projection SET age = NULL WHERE import_id = :importId AND age = 50',
            ['importId' => $import->getId()],
        );

        return [$user, $hospital->getId()];
    }
}
