<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisSummaryPart;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisSummaryFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisSummaryFilterLabelsInterface;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisSummaryLabelResolverInterface;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExplorerAnalysisSummaryFactoryTest extends TestCase
{
    public function testDefaultTemporalAllocationsSummary(): void
    {
        $summary = $this->factory()->create($this->baseConfig());

        self::assertSame(
            'Allocations for all hospitals in Last 12 months, shown monthly.',
            $summary->plainText(),
        );
        self::assertTrue($this->hasEmphasized($summary->parts, 'Allocations'));
        self::assertTrue($this->hasEmphasized($summary->parts, 'all hospitals'));
        self::assertTrue($this->hasEmphasized($summary->parts, 'Last 12 months'));
        self::assertTrue($this->hasEmphasized($summary->parts, 'monthly'));
        self::assertSame([], $summary->abbreviatedFilterLabels);
    }

    public function testExplicitHospitalAndYearPeriod(): void
    {
        $config = $this->baseConfig(
            filter: new StatisticsFilter(
                scope: StatisticsFilterScope::Hospital,
                hospitalId: 17,
                cohortType: null,
                period: StatisticsFilterPeriod::Year,
                referenceYear: 2025,
            ),
        );

        $summary = $this->factory(scopeLabel: 'Klinikum A', periodLabel: '2025')->create($config);

        self::assertSame(
            'Allocations for Klinikum A in 2025, shown monthly.',
            $summary->plainText(),
        );
    }

    public function testChangedMetricAndYearlyGrain(): void
    {
        $config = $this->baseConfig(
            metric: AnalysisMetricKey::ResusRate,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Year),
        );

        $summary = $this->factory()->create($config);

        self::assertSame(
            'Resuscitation rate for all hospitals in Last 12 months, shown yearly.',
            $summary->plainText(),
        );
    }

    public function testBreakdownWithoutTopN(): void
    {
        $config = $this->baseConfig(
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Indication),
        );

        $summary = $this->factory()->create($config);

        self::assertSame(
            'Allocations for all hospitals in Last 12 months, grouped by Indication.',
            $summary->plainText(),
        );
    }

    public function testToplistUsesTopNWording(): void
    {
        $config = $this->baseConfig(
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Indication),
            presentation: new PresentationConfig(
                chartType: ChartPresentationType::Bar,
                chartRowLimit: ExplorerChartRowLimit::Top10,
            ),
        );

        $summary = $this->factory()->create($config);

        self::assertSame(
            'Top 10 Indication for all hospitals in Last 12 months, measured by Allocations.',
            $summary->plainText(),
        );
        self::assertTrue($this->hasEmphasized($summary->parts, '10'));
    }

    public function testOverTimeBreakdown(): void
    {
        $config = $this->baseConfig(
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
        );

        $summary = $this->factory()->create($config);

        self::assertSame(
            'Allocations for all hospitals in Last 12 months, shown monthly by Gender.',
            $summary->plainText(),
        );
    }

    public function testMatrixCrossTab(): void
    {
        $config = $this->baseConfig(
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
        );

        $summary = $this->factory()->create($config);

        self::assertSame(
            'Allocations by Urgency and Gender for all hospitals in Last 12 months.',
            $summary->plainText(),
        );
    }

    public function testMatrixWithDayColumnUsesDayNoun(): void
    {
        $config = $this->baseConfig(
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
            columnAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Day),
        );

        $summary = $this->factory()->create($config);

        self::assertSame(
            'Allocations by Urgency and Day for all hospitals in Last 12 months.',
            $summary->plainText(),
        );
        self::assertTrue($this->hasEmphasized($summary->parts, 'Day'));
    }

    public function testSingleFilterIsInline(): void
    {
        $config = $this->baseConfig(filters: [
            new AnalysisFilter('urgency', AnalysisFilterOperator::Equals, 1),
        ]);

        $summary = $this->factory(filterBadges: [
            ['label' => 'Urgency', 'value' => '1'],
        ])->create($config);

        self::assertSame(
            'Allocations for all hospitals in Last 12 months, shown monthly. Filtered to Urgency: 1.',
            $summary->plainText(),
        );
        self::assertTrue($this->hasEmphasized($summary->parts, 'Urgency: 1'));
        self::assertSame([], $summary->abbreviatedFilterLabels);
    }

    public function testTwoFiltersAreInline(): void
    {
        $config = $this->baseConfig(filters: [
            new AnalysisFilter('urgency', AnalysisFilterOperator::Equals, 1),
            new AnalysisFilter('resus', AnalysisFilterOperator::Equals, 1),
        ]);

        $summary = $this->factory(filterBadges: [
            ['label' => 'Urgency', 'value' => '1'],
            ['label' => 'Shock room', 'value' => 'Yes'],
        ])->create($config);

        self::assertSame(
            'Allocations for all hospitals in Last 12 months, shown monthly. Filtered to Urgency: 1 and Shock room: Yes.',
            $summary->plainText(),
        );
    }

    public function testManyFiltersAreAbbreviated(): void
    {
        $config = $this->baseConfig(filters: [
            new AnalysisFilter('urgency', AnalysisFilterOperator::Equals, 1),
            new AnalysisFilter('resus', AnalysisFilterOperator::Equals, 1),
            new AnalysisFilter('gender', AnalysisFilterOperator::Equals, 2),
            new AnalysisFilter('cpr', AnalysisFilterOperator::Equals, 1),
            new AnalysisFilter('ventilation', AnalysisFilterOperator::Equals, 0),
        ]);

        $summary = $this->factory(filterBadges: [
            ['label' => 'Urgency', 'value' => '1'],
            ['label' => 'Shock room', 'value' => 'Yes'],
            ['label' => 'Gender', 'value' => 'Female'],
            ['label' => 'CPR', 'value' => 'Yes'],
            ['label' => 'Ventilation', 'value' => 'No'],
        ])->create($config);

        self::assertSame(
            'Allocations for all hospitals in Last 12 months, shown monthly. Filtered to Urgency: 1 and Shock room: Yes, with 3 more filters.',
            $summary->plainText(),
        );
        self::assertCount(5, $summary->abbreviatedFilterLabels);
        self::assertTrue($this->hasEmphasized($summary->parts, 'with 3 more filters'));
    }

    public function testDistributionProfileSummary(): void
    {
        $config = $this->baseConfig(
            metric: AnalysisMetricKey::TransportTimeDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Hospital),
        );

        $summary = $this->factory()->create($config);

        self::assertSame(
            'Transport time distribution (box plot) for all hospitals in Last 12 months, grouped by Hospital.',
            $summary->plainText(),
        );
    }

    public function testHospitalsParticipatingDefaultOmitsPublicScope(): void
    {
        $config = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::HospitalCount],
            visualMetricKey: AnalysisMetricKey::HospitalCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalMasterCohort),
            columnAxis: null,
            statisticsFilter: $this->defaultFilter(),
            presentation: new PresentationConfig(ChartPresentationType::Bar),
            title: 'Hospitals',
            hospitalPopulationMode: ExplorerHospitalPopulationMode::Participating,
        );

        $summary = $this->factory()->create($config);

        self::assertSame(
            'Hospitals for Participating hospitals in Last 12 months, grouped by Master cohort.',
            $summary->plainText(),
        );
    }

    public function testHospitalsCompareMode(): void
    {
        $config = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::HospitalCount],
            visualMetricKey: AnalysisMetricKey::HospitalCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: null,
            statisticsFilter: $this->defaultFilter(),
            presentation: new PresentationConfig(ChartPresentationType::Bar),
            title: 'Hospitals',
            hospitalPopulationMode: ExplorerHospitalPopulationMode::Compare,
        );

        $summary = $this->factory()->create($config);

        self::assertSame(
            'Hospitals comparing participating and non-participating hospitals in Last 12 months, grouped by Hospital tier.',
            $summary->plainText(),
        );
    }

    public function testGermanLocaleUsesGermanTemplates(): void
    {
        $summary = $this->factory(localeAware: true)->create($this->baseConfig(), locale: 'de');

        self::assertSame(
            'Zuweisungen für alle Krankenhäuser im Zeitraum Letzte 12 Monate, dargestellt monatlich.',
            $summary->plainText(),
        );
    }

    public function testQuarterAndWeekGrainsUseDedicatedAdverbs(): void
    {
        $quarter = $this->factory()->create($this->baseConfig(
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Quarter),
        ));
        self::assertStringContainsString('by quarter', $quarter->plainText());

        $week = $this->factory()->create($this->baseConfig(
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Week),
        ));
        self::assertStringContainsString('weekly', $week->plainText());
    }

    public function testMatrixWithTemporalColumnUsesGrainNoun(): void
    {
        $summary = $this->factory()->create($this->baseConfig(
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
            columnAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
        ));

        self::assertSame(
            'Allocations by Urgency and Month for all hospitals in Last 12 months.',
            $summary->plainText(),
        );
    }

    public function testMatrixWithTemporalGrainOnBreakdownAxis(): void
    {
        $summary = $this->factory()->create($this->baseConfig(
            rowAxis: new AnalysisAxisRef(AnalysisDimensionKey::Gender, AnalysisDimensionGrain::Month),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
        ));

        self::assertSame(
            'Allocations by Gender (Month) and Urgency for all hospitals in Last 12 months.',
            $summary->plainText(),
        );
    }

    public function testHospitalsScopedAndMatrixVariants(): void
    {
        $scoped = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::HospitalCount],
            visualMetricKey: AnalysisMetricKey::HospitalCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::State,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
                stateId: 1,
            ),
            presentation: new PresentationConfig(ChartPresentationType::Bar),
            title: 'Hospitals',
            hospitalPopulationMode: ExplorerHospitalPopulationMode::Participating,
        );

        self::assertSame(
            'Hospitals for Participating hospitals in Hessen during Last 12 months, grouped by Hospital tier.',
            $this->factory(scopeLabel: 'Hessen')->create($scoped)->plainText(),
        );

        $matrix = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::HospitalCount],
            visualMetricKey: AnalysisMetricKey::HospitalCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalLocation),
            statisticsFilter: $this->defaultFilter(),
            presentation: new PresentationConfig(ChartPresentationType::Bar),
            title: 'Hospitals',
            hospitalPopulationMode: ExplorerHospitalPopulationMode::All,
        );

        self::assertSame(
            'Hospitals for All hospitals by Hospital tier and Hospital location in Last 12 months.',
            $this->factory()->create($matrix)->plainText(),
        );

        $matrixScoped = $matrix->withStatisticsFilter(new StatisticsFilter(
            scope: StatisticsFilterScope::DispatchArea,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
            dispatchAreaId: 2,
        ));

        self::assertSame(
            'Hospitals for All hospitals by Hospital tier and Hospital location in Nordhessen during Last 12 months.',
            $this->factory(scopeLabel: 'Nordhessen')->create($matrixScoped)->plainText(),
        );

        $compareScoped = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::HospitalCount],
            visualMetricKey: AnalysisMetricKey::HospitalCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Hospital,
                hospitalId: 1,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(ChartPresentationType::Bar),
            title: 'Hospitals',
            hospitalPopulationMode: ExplorerHospitalPopulationMode::Compare,
        );

        self::assertSame(
            'Hospitals comparing participating and non-participating hospitals in Klinikum A during Last 12 months, grouped by Hospital tier.',
            $this->factory(scopeLabel: 'Klinikum A')->create($compareScoped)->plainText(),
        );
    }

    public function testEmptyFilterBadgesDoNotAppendFilterSentence(): void
    {
        $summary = $this->factory(filterBadges: [])->create($this->baseConfig(filters: [
            new AnalysisFilter('urgency', AnalysisFilterOperator::Equals, 1),
        ]));

        self::assertStringNotContainsString('Filtered to', $summary->plainText());
    }

    /**
     * @param list<array{label: string, value: string}> $filterBadges
     */
    private function factory(
        string $scopeLabel = 'all hospitals',
        string $periodLabel = 'Last 12 months',
        array $filterBadges = [],
        bool $localeAware = false,
    ): ExplorerAnalysisSummaryFactory {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null) use ($localeAware): string {
                $map = self::translationMap($localeAware ? $locale : 'en');
                $template = $map[$id] ?? $id;

                if (str_contains($template, 'plural')) {
                    $count = (int) ($parameters['count'] ?? 0);
                    if ('de' === $locale) {
                        return 1 === $count
                            ? 'mit '.$count.' weiterem Filter'
                            : 'mit '.$count.' weiteren Filtern';
                    }

                    return 1 === $count
                        ? 'with '.$count.' more filter'
                        : 'with '.$count.' more filters';
                }

                return strtr($template, array_combine(
                    array_map(static fn (string|int $key): string => '{'.$key.'}', array_keys($parameters)),
                    array_map(static fn (mixed $value): string => (string) $value, array_values($parameters)),
                ));
            },
        );

        $labelResolver = $this->createStub(ExplorerAnalysisSummaryLabelResolverInterface::class);
        $labelResolver->method('scopeLabel')->willReturnCallback(
            static function (mixed $filter, mixed $user, ?string $locale = null) use ($scopeLabel, $localeAware): string {
                if ($localeAware && 'de' === $locale) {
                    return 'alle Krankenhäuser';
                }

                return $scopeLabel;
            },
        );
        $labelResolver->method('periodLabel')->willReturnCallback(
            static function (mixed $filter, ?string $locale = null) use ($periodLabel, $localeAware): string {
                if ($localeAware && 'de' === $locale) {
                    return 'Letzte 12 Monate';
                }

                return $periodLabel;
            },
        );

        $filterPresenter = $this->createStub(ExplorerAnalysisSummaryFilterLabelsInterface::class);
        $filterPresenter->method('present')->willReturn($filterBadges);

        return new ExplorerAnalysisSummaryFactory($translator, $labelResolver, $filterPresenter);
    }

    /**
     * @return array<string, string>
     */
    private static function translationMap(?string $locale): array
    {
        if ('de' === $locale) {
            return [
                'stats.analysis_explorer.summary.temporal' => '{subject} für {scope} im Zeitraum {period}, dargestellt {grouping}.',
                'stats.analysis_explorer.summary.breakdown' => '{subject} für {scope} im Zeitraum {period}, gruppiert nach {dimension}.',
                'stats.analysis_explorer.summary.toplist' => 'Top {limit} {dimension} für {scope} im Zeitraum {period}, gemessen nach {metric}.',
                'stats.analysis_explorer.summary.over_time' => '{subject} für {scope} im Zeitraum {period}, dargestellt {grouping} nach {dimension}.',
                'stats.analysis_explorer.summary.matrix' => '{subject} nach {rows} und {columns} für {scope} im Zeitraum {period}.',
                'stats.analysis_explorer.summary.distribution_profile' => '{subject} für {scope} im Zeitraum {period}, gruppiert nach {dimension}.',
                'stats.analysis_explorer.summary.hospitals' => '{subject} für {population} im Zeitraum {period}, gruppiert nach {dimension}.',
                'stats.analysis_explorer.summary.hospitals_compare' => '{subject} im Vergleich teilnehmender und nicht teilnehmender Krankenhäuser im Zeitraum {period}, gruppiert nach {dimension}.',
                'stats.analysis_explorer.summary.grain.month' => 'monatlich',
                'stats.analysis_explorer.summary.grain.year' => 'jährlich',
                'stats.analysis_explorer.summary.filtered_prefix' => ' Gefiltert auf',
                'stats.analysis_explorer.summary.filters_and' => ' und ',
                'stats.analysis_explorer.summary.filters_comma' => ', ',
                'stats.analysis_explorer.summary.filters_more_separator' => ', ',
                'stats.analysis_explorer.summary.more_filters' => '{count, plural}',
                'stats.analysis_explorer.metric.allocation_count' => 'Zuweisungen',
                'stats.analysis_explorer.metric.resus_rate' => 'Schockraumquote',
                'stats.analysis_explorer.metric.hospital_count' => 'Krankenhäuser',
                'stats.analysis_explorer.metric_profile.transport_time_distribution' => 'Transportzeit – Verteilung (Box-Plot)',
                'stats.analysis_explorer.dimension.indication' => 'Indikation',
                'stats.analysis_explorer.dimension.gender' => 'Geschlecht',
                'stats.analysis_explorer.dimension.urgency' => 'Dringlichkeit',
                'stats.analysis_explorer.dimension.hospital' => 'Krankenhaus',
                'stats.analysis_explorer.dimension.hospital_master_cohort' => 'Master-Kohorte',
                'stats.analysis_explorer.dimension.hospital_tier' => 'Versorgungsstufe',
                'stats.analysis_explorer.hospital_population.participating' => 'Teilnehmende Krankenhäuser',
            ];
        }

        return [
            'stats.analysis_explorer.summary.temporal' => '{subject} for {scope} in {period}, shown {grouping}.',
            'stats.analysis_explorer.summary.breakdown' => '{subject} for {scope} in {period}, grouped by {dimension}.',
            'stats.analysis_explorer.summary.toplist' => 'Top {limit} {dimension} for {scope} in {period}, measured by {metric}.',
            'stats.analysis_explorer.summary.over_time' => '{subject} for {scope} in {period}, shown {grouping} by {dimension}.',
            'stats.analysis_explorer.summary.matrix' => '{subject} by {rows} and {columns} for {scope} in {period}.',
            'stats.analysis_explorer.summary.distribution_profile' => '{subject} for {scope} in {period}, grouped by {dimension}.',
            'stats.analysis_explorer.summary.hospitals' => '{subject} for {population} in {period}, grouped by {dimension}.',
            'stats.analysis_explorer.summary.hospitals_scoped' => '{subject} for {population} in {scope} during {period}, grouped by {dimension}.',
            'stats.analysis_explorer.summary.hospitals_matrix' => '{subject} for {population} by {rows} and {columns} in {period}.',
            'stats.analysis_explorer.summary.hospitals_matrix_scoped' => '{subject} for {population} by {rows} and {columns} in {scope} during {period}.',
            'stats.analysis_explorer.summary.hospitals_compare' => '{subject} comparing participating and non-participating hospitals in {period}, grouped by {dimension}.',
            'stats.analysis_explorer.summary.hospitals_compare_scoped' => '{subject} comparing participating and non-participating hospitals in {scope} during {period}, grouped by {dimension}.',
            'stats.analysis_explorer.summary.grain.month' => 'monthly',
            'stats.analysis_explorer.summary.grain.year' => 'yearly',
            'stats.analysis_explorer.summary.grain.quarter' => 'by quarter',
            'stats.analysis_explorer.summary.grain.week' => 'weekly',
            'stats.analysis_explorer.summary.grain.total' => 'as a total',
            'stats.analysis_explorer.summary.dimension_by_grain' => '{dimension} ({grain})',
            'stats.analysis_explorer.summary.filtered_prefix' => ' Filtered to',
            'stats.analysis_explorer.summary.filters_and' => ' and ',
            'stats.analysis_explorer.summary.filters_comma' => ', ',
            'stats.analysis_explorer.summary.filters_more_separator' => ', ',
            'stats.analysis_explorer.summary.more_filters' => '{count, plural}',
            'stats.analysis_explorer.metric.allocation_count' => 'Allocations',
            'stats.analysis_explorer.metric.resus_rate' => 'Resuscitation rate',
            'stats.analysis_explorer.metric.hospital_count' => 'Hospitals',
            'stats.analysis_explorer.metric_profile.transport_time_distribution' => 'Transport time distribution (box plot)',
            'stats.analysis_explorer.dimension.indication' => 'Indication',
            'stats.analysis_explorer.dimension.gender' => 'Gender',
            'stats.analysis_explorer.dimension.urgency' => 'Urgency',
            'stats.analysis_explorer.dimension.hospital' => 'Hospital',
            'stats.analysis_explorer.dimension.hospital_master_cohort' => 'Master cohort',
            'stats.analysis_explorer.dimension.hospital_tier' => 'Hospital tier',
            'stats.analysis_explorer.dimension.hospital_location' => 'Hospital location',
            'stats.analysis_explorer.dimension.month' => 'Month',
            'stats.analysis_explorer.dimension.day' => 'Day',
            'stats.analysis_explorer.hospital_population.participating' => 'Participating hospitals',
            'stats.analysis_explorer.hospital_population.all' => 'All hospitals',
        ];
    }

    /**
     * @param list<AnalysisFilter> $filters
     */
    private function baseConfig(
        ?StatisticsFilter $filter = null,
        AnalysisMetricKey $metric = AnalysisMetricKey::AllocationCount,
        ?AnalysisAxisRef $rowAxis = null,
        ?AnalysisAxisRef $columnAxis = null,
        ?PresentationConfig $presentation = null,
        array $filters = [],
    ): AnalysisViewConfig {
        return new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [$metric],
            visualMetricKey: $metric,
            rowAxis: $rowAxis ?? AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: $columnAxis,
            statisticsFilter: $filter ?? $this->defaultFilter(),
            presentation: $presentation ?? new PresentationConfig(ChartPresentationType::Bar),
            title: 'Test',
            filters: $filters,
        );
    }

    private function defaultFilter(): StatisticsFilter
    {
        return new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
    }

    /**
     * @param list<AnalysisSummaryPart> $parts
     */
    private function hasEmphasized(array $parts, string $text): bool
    {
        return array_any($parts, fn (AnalysisSummaryPart $part): bool => $part->emphasize && $part->text === $text);
    }
}
