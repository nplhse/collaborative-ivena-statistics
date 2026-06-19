<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Overview;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\Overview\OverviewKpiPresentationFactory;
use App\Statistics\Application\Overview\OverviewScopeClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OverviewKpiPresentationFactoryTest extends TestCase
{
    private OverviewKpiPresentationFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new OverviewKpiPresentationFactory();
    }

    #[DataProvider('aggregateScopeProvider')]
    public function testUsesAggregateCasesPerDayLabelForBroadScopes(StatisticsFilterScope $scope): void
    {
        $keys = $this->factory->metricLabelKeys($this->filterForScope($scope));

        self::assertSame('stats.overview.kpi.cases_per_day_aggregate', $keys['cases_per_day']);
        self::assertSame(
            'stats.overview.kpi.cases_per_day_aggregate_hint',
            $this->factory->casesPerDayHintTranslationKey($this->filterForScope($scope)),
        );
    }

    #[DataProvider('hospitalLikeScopeProvider')]
    public function testUsesHospitalCasesPerDayLabelForHospitalScopes(StatisticsFilterScope $scope): void
    {
        $keys = $this->factory->metricLabelKeys($this->filterForScope($scope));

        self::assertSame('stats.overview.kpi.cases_per_day', $keys['cases_per_day']);
        self::assertNull($this->factory->casesPerDayHintTranslationKey($this->filterForScope($scope)));
    }

    /**
     * @return iterable<string, array{StatisticsFilterScope}>
     */
    public static function aggregateScopeProvider(): iterable
    {
        yield 'public' => [StatisticsFilterScope::Public];
        yield 'state' => [StatisticsFilterScope::State];
        yield 'dispatch area' => [StatisticsFilterScope::DispatchArea];
        yield 'hospital cohort' => [StatisticsFilterScope::HospitalCohort];
    }

    /**
     * @return iterable<string, array{StatisticsFilterScope}>
     */
    public static function hospitalLikeScopeProvider(): iterable
    {
        yield 'my hospitals' => [StatisticsFilterScope::MyHospitals];
        yield 'hospital' => [StatisticsFilterScope::Hospital];
    }

    public function testScopeClassifierMatchesExpectations(): void
    {
        self::assertTrue(OverviewScopeClassifier::isAggregateScope(StatisticsFilterScope::Public));
        self::assertFalse(OverviewScopeClassifier::isAggregateScope(StatisticsFilterScope::Hospital));
    }

    private function filterForScope(StatisticsFilterScope $scope): StatisticsFilter
    {
        return new StatisticsFilter(
            $scope,
            StatisticsFilterScope::Hospital === $scope ? 1 : null,
            null,
            StatisticsFilterPeriod::All,
            stateId: StatisticsFilterScope::State === $scope ? 1 : null,
            dispatchAreaId: StatisticsFilterScope::DispatchArea === $scope ? 1 : null,
        );
    }
}
