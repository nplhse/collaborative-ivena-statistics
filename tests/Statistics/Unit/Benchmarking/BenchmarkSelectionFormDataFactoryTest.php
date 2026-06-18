<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Benchmarking;

use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionFormDataFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkSelectionFormDataFactoryTest extends TestCase
{
    private BenchmarkSelectionFormDataFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new BenchmarkSelectionFormDataFactory();
    }

    #[Test]
    public function fromFiltersMapsAllScopeVariants(): void
    {
        $cohort = new HospitalCohortKey(\App\Allocation\Domain\Enum\HospitalLocation::URBAN, \App\Allocation\Domain\Enum\HospitalTier::FULL);
        $comparison = new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::AllTime);

        $public = $this->factory->fromFilters(
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All),
            $comparison,
        );
        self::assertSame('public', $public->primary->scopeGroup);
        self::assertNull($public->primary->scopeDetail);

        $state = $this->factory->fromFilters(
            new StatisticsFilter(StatisticsFilterScope::State, null, null, StatisticsFilterPeriod::Year, 2024, stateId: 3),
            $comparison,
        );
        self::assertSame('state', $state->primary->scopeGroup);
        self::assertSame('3', $state->primary->scopeDetail);
        self::assertSame('year', $state->primary->period);
        self::assertSame(2024, $state->primary->periodYear);

        $dispatch = $this->factory->fromFilters(
            new StatisticsFilter(StatisticsFilterScope::DispatchArea, null, null, StatisticsFilterPeriod::Quarter, 2023, null, 2, dispatchAreaId: 8),
            $comparison,
        );
        self::assertSame('dispatch_area', $dispatch->primary->scopeGroup);
        self::assertSame('8', $dispatch->primary->scopeDetail);
        self::assertSame(2, $dispatch->primary->periodQuarter);

        $cohortFilter = $this->factory->fromFilters(
            new StatisticsFilter(StatisticsFilterScope::HospitalCohort, null, $cohort, StatisticsFilterPeriod::AllTime),
            $comparison,
        );
        self::assertSame('hospital_cohort', $cohortFilter->primary->scopeGroup);
        self::assertSame('urban_full', $cohortFilter->primary->scopeDetail);

        $myHospitals = $this->factory->fromFilters(
            new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All),
            $comparison,
        );
        self::assertSame('my_hospitals', $myHospitals->primary->scopeGroup);
        self::assertNull($myHospitals->primary->scopeDetail);

        $hospital = $this->factory->fromFilters(
            new StatisticsFilter(StatisticsFilterScope::Hospital, 42, null, StatisticsFilterPeriod::Month, 2025, 6),
            $comparison,
        );
        self::assertSame('my_hospitals', $hospital->primary->scopeGroup);
        self::assertSame('42', $hospital->primary->scopeDetail);
        self::assertSame(6, $hospital->primary->periodMonth);
    }

    #[Test]
    #[DataProvider('defaultPeriodReferenceProvider')]
    public function fromFiltersFallsBackToCurrentPeriodReferencesWhenMissing(
        StatisticsFilterScope $scope,
        StatisticsFilterPeriod $period,
        string $expectedScopeGroup,
    ): void {
        $comparison = new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::AllTime);
        $now = new \DateTimeImmutable();
        $expectedYear = (int) $now->format('Y');
        $expectedMonth = (int) $now->format('n');
        $expectedQuarter = (int) ceil($expectedMonth / 3);

        $data = $this->factory->fromFilters(
            new StatisticsFilter($scope, null, null, $period),
            $comparison,
        );

        self::assertSame($expectedScopeGroup, $data->primary->scopeGroup);
        self::assertSame($expectedYear, $data->primary->periodYear);
        if (StatisticsFilterPeriod::Quarter === $period) {
            self::assertSame($expectedQuarter, $data->primary->periodQuarter);
        }
        if (StatisticsFilterPeriod::Month === $period) {
            self::assertSame($expectedMonth, $data->primary->periodMonth);
        }
    }

    /**
     * @return iterable<string, array{StatisticsFilterScope, StatisticsFilterPeriod, string}>
     */
    public static function defaultPeriodReferenceProvider(): iterable
    {
        yield 'public year' => [StatisticsFilterScope::Public, StatisticsFilterPeriod::Year, 'public'];
        yield 'public quarter' => [StatisticsFilterScope::Public, StatisticsFilterPeriod::Quarter, 'public'];
        yield 'public month' => [StatisticsFilterScope::Public, StatisticsFilterPeriod::Month, 'public'];
    }
}
