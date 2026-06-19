<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Registry;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewDefinition;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDisplayMode;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisSeriesMode;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewCategory;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisViewException;

final class AnalysisViewRegistry
{
    /** @var array<string, AnalysisViewDefinition> */
    private array $views = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function get(string $key): AnalysisViewDefinition
    {
        return $this->views[$key] ?? throw UnknownAnalysisViewException::forKey($key);
    }

    public function has(string $key): bool
    {
        return isset($this->views[$key]);
    }

    /**
     * @return list<AnalysisViewDefinition>
     */
    public function all(): array
    {
        return array_values($this->views);
    }

    /**
     * @return list<AnalysisViewDefinition>
     */
    public function featured(): array
    {
        return array_values(array_filter(
            $this->views,
            static fn (AnalysisViewDefinition $view): bool => $view->isFeatured,
        ));
    }

    /**
     * @return list<AnalysisViewDefinition>
     */
    public function byCategory(AnalysisViewCategory $category): array
    {
        return array_values(array_filter(
            $this->views,
            static fn (AnalysisViewDefinition $view): bool => $view->category === $category,
        ));
    }

    /**
     * @return list<AnalysisViewDefinition>
     */
    public function byTag(string $tag): array
    {
        $needle = strtolower($tag);

        return array_values(array_filter(
            $this->views,
            static fn (AnalysisViewDefinition $view): bool => \in_array($needle, array_map(strtolower(...), $view->tags), true),
        ));
    }

    private function register(AnalysisViewDefinition $view): void
    {
        $this->views[$view->key] = $view;
    }

    private function registerDefaults(): void
    {
        $bar = [GenericAnalysisChartType::Bar, GenericAnalysisChartType::HorizontalBar];
        $line = [GenericAnalysisChartType::Line, GenericAnalysisChartType::Bar];
        $stacked = [GenericAnalysisChartType::StackedBar, GenericAnalysisChartType::GroupedBar, GenericAnalysisChartType::Line];

        $this->register($this->view(
            key: 'allocations_by_month',
            title: 'Allocations by month',
            description: 'How many allocations occur each month?',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['time', 'month', 'allocations', 'audience:operations'],
            primary: 'month',
            chart: GenericAnalysisChartType::Line,
            allowed: $line,
            featured: true,
        ));

        $this->register($this->view(
            key: 'allocations_by_weekday',
            title: 'Allocations by weekday',
            description: 'On which weekdays do most allocations occur?',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['time', 'weekday', 'allocations', 'audience:operations'],
            primary: 'weekday',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
        ));

        $this->register($this->view(
            key: 'allocations_by_hour',
            title: 'Allocations by hour',
            description: 'At what times of day do allocations peak?',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['time', 'hour', 'allocations', 'audience:operations'],
            primary: 'hour',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
        ));

        $this->register($this->view(
            key: 'urgency_by_month',
            title: 'Urgency by month',
            description: 'How does the urgency mix change over time?',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['time', 'month', 'urgency', 'audience:clinical'],
            primary: 'month',
            chart: GenericAnalysisChartType::StackedBar,
            allowed: $stacked,
            secondary: 'urgency',
            featured: true,
        ));

        $this->register($this->view(
            key: 'gender_distribution',
            title: 'Gender distribution',
            description: 'What is the overall gender mix of patients?',
            category: AnalysisViewCategory::Patients,
            tags: ['gender', 'patients'],
            primary: 'gender',
            chart: GenericAnalysisChartType::Pie,
            allowed: [GenericAnalysisChartType::Pie, GenericAnalysisChartType::Bar, GenericAnalysisChartType::HorizontalBar],
            includeNullBuckets: false,
        ));

        $this->register($this->view(
            key: 'gender_distribution_by_urgency',
            title: 'Gender distribution by urgency',
            description: 'Does the gender mix differ between emergency and elective allocations?',
            category: AnalysisViewCategory::Patients,
            tags: ['gender', 'urgency', 'patients', 'audience:clinical'],
            primary: 'urgency',
            chart: GenericAnalysisChartType::StackedBar,
            allowed: $stacked,
            secondary: 'gender',
            featured: true,
            includeNullBuckets: false,
        ));

        $this->register($this->view(
            key: 'resus_by_hour',
            title: 'Resuscitation by hour',
            description: 'When do resuscitation cases occur during the day?',
            category: AnalysisViewCategory::Operations,
            tags: ['resus', 'time', 'hour', 'audience:clinical'],
            primary: 'hour',
            chart: GenericAnalysisChartType::StackedBar,
            allowed: $stacked,
            secondary: 'resus',
        ));

        $this->register($this->view(
            key: 'resus_rate_by_hour',
            title: 'Resuscitation rate by hour',
            description: 'What share of allocations require resuscitation at each hour?',
            category: AnalysisViewCategory::Operations,
            tags: ['resus', 'time', 'hour', 'metric:rate', 'audience:clinical'],
            primary: 'hour',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            metrics: ['count', 'resus_rate'],
            visualMetricKey: 'resus_rate',
            featured: true,
        ));

        $this->register($this->view(
            key: 'age_group_distribution',
            title: 'Age group distribution',
            description: 'Which age groups are most frequently allocated?',
            category: AnalysisViewCategory::Patients,
            tags: ['age', 'patients'],
            primary: 'age_group',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
        ));

        $this->register($this->view(
            key: 'allocations_by_hospital_cohort',
            title: 'Allocations by hospital cohort',
            description: 'How are allocations distributed across hospital cohort types?',
            category: AnalysisViewCategory::Hospitals,
            tags: ['hospital', 'cohort', 'allocations', 'audience:operations'],
            primary: 'hospital_cohort',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
        ));

        $this->register($this->view(
            key: 'urgency_by_hospital_cohort',
            title: 'Urgency by hospital cohort',
            description: 'How does urgency differ between hospital cohort types?',
            category: AnalysisViewCategory::Hospitals,
            tags: ['hospital', 'urgency', 'cohort', 'audience:clinical'],
            primary: 'hospital_cohort',
            chart: GenericAnalysisChartType::StackedBar,
            allowed: $stacked,
            secondary: 'urgency',
        ));

        $this->register($this->view(
            key: 'allocations_by_month_with_share',
            title: 'Allocations by month (with share)',
            description: 'What percentage of total allocations falls in each month?',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['time', 'month', 'allocations', 'metric:share', 'audience:operations'],
            primary: 'month',
            chart: GenericAnalysisChartType::Line,
            allowed: $line,
            metrics: ['count', 'percent_of_total'],
        ));

        $this->register($this->view(
            key: 'urgency_distribution_with_share',
            title: 'Urgency distribution (with share)',
            description: 'What share of all allocations falls into each urgency level?',
            category: AnalysisViewCategory::Clinical,
            tags: ['urgency', 'metric:share', 'audience:clinical'],
            primary: 'urgency',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            metrics: ['count', 'percent_of_total'],
        ));

        $this->register($this->view(
            key: 'urgency_by_month_with_bucket_share',
            title: 'Urgency by month (bucket share)',
            description: 'Within each month, what share belongs to each urgency level?',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['time', 'month', 'urgency', 'metric:share', 'audience:clinical'],
            primary: 'month',
            chart: GenericAnalysisChartType::StackedBar,
            allowed: $stacked,
            secondary: 'urgency',
            metrics: ['count', 'percent_of_bucket'],
        ));

        $this->register($this->view(
            key: 'transport_time_by_department',
            title: 'Transport time by department',
            description: 'How long does transport take per department (median and P90)?',
            category: AnalysisViewCategory::Operations,
            tags: ['transport', 'department', 'metric:transport', 'audience:operations'],
            primary: 'department',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            metrics: ['count', 'median_transport_time', 'p90_transport_time'],
            visualMetricKey: 'median_transport_time',
        ));

        $this->register($this->view(
            key: 'transport_time_by_urgency',
            title: 'Transport time by urgency',
            description: 'How long does transport take for emergency vs. elective allocations?',
            category: AnalysisViewCategory::Clinical,
            tags: ['transport', 'urgency', 'metric:transport', 'audience:clinical'],
            primary: 'urgency',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            metrics: ['count', 'median_transport_time', 'p90_transport_time'],
            visualMetricKey: 'median_transport_time',
            featured: true,
        ));

        $this->register($this->view(
            key: 'transport_time_by_month',
            title: 'Transport time by month',
            description: 'How does transport time evolve over the year?',
            category: AnalysisViewCategory::Operations,
            tags: ['transport', 'month', 'time', 'metric:transport', 'audience:operations'],
            primary: 'month',
            chart: GenericAnalysisChartType::Line,
            allowed: $line,
            metrics: ['count', 'median_transport_time', 'p90_transport_time'],
            visualMetricKey: 'median_transport_time',
        ));

        $this->register($this->view(
            key: 'resus_by_department',
            title: 'Resuscitation by department',
            description: 'Which departments have the highest resuscitation rates?',
            category: AnalysisViewCategory::Clinical,
            tags: ['resus', 'department', 'metric:rate', 'audience:clinical'],
            primary: 'department',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            metrics: ['count', 'resus_rate'],
            visualMetricKey: 'resus_rate',
        ));

        $this->register($this->view(
            key: 'shock_rate_by_department',
            title: 'Shock rate by department',
            description: 'Which departments see the most shock cases relative to volume?',
            category: AnalysisViewCategory::Clinical,
            tags: ['shock', 'department', 'metric:rate', 'audience:clinical'],
            primary: 'department',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            metrics: ['count', 'shock_rate'],
            visualMetricKey: 'shock_rate',
        ));

        $this->register($this->view(
            key: 'ventilation_rate_by_hour',
            title: 'Ventilation rate by hour',
            description: 'At which hours is ventilation most frequently required?',
            category: AnalysisViewCategory::Operations,
            tags: ['ventilation', 'hour', 'time', 'metric:rate', 'audience:operations'],
            primary: 'hour',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            metrics: ['count', 'ventilation_rate'],
            visualMetricKey: 'ventilation_rate',
        ));

        $this->register($this->view(
            key: 'cathlab_rate_by_speciality',
            title: 'Cath lab rate by speciality',
            description: 'Which specialities most often require cath lab intervention?',
            category: AnalysisViewCategory::Clinical,
            tags: ['cathlab', 'speciality', 'metric:rate', 'audience:clinical'],
            primary: 'speciality',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            metrics: ['count', 'cathlab_rate'],
            visualMetricKey: 'cathlab_rate',
        ));

        $this->register($this->view(
            key: 'pregnancy_rate_by_month',
            title: 'Pregnancy rate by month',
            description: 'How does the pregnancy rate trend over time?',
            category: AnalysisViewCategory::Clinical,
            tags: ['pregnancy', 'month', 'time', 'metric:rate', 'audience:clinical'],
            primary: 'month',
            chart: GenericAnalysisChartType::Line,
            allowed: $line,
            metrics: ['count', 'pregnancy_rate'],
            visualMetricKey: 'pregnancy_rate',
        ));

        $this->register($this->view(
            key: 'work_accident_rate_by_month',
            title: 'Work accident rate by month',
            description: 'How does the work accident rate develop month by month?',
            category: AnalysisViewCategory::Operations,
            tags: ['work_accident', 'month', 'time', 'metric:rate', 'audience:admin'],
            primary: 'month',
            chart: GenericAnalysisChartType::Line,
            allowed: $line,
            metrics: ['count', 'work_accident_rate'],
            visualMetricKey: 'work_accident_rate',
        ));

        $this->register($this->view(
            key: 'cpr_by_month',
            title: 'CPR by month',
            description: 'Is the CPR rate increasing or decreasing over time?',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['cpr', 'month', 'metric:rate', 'audience:clinical'],
            primary: 'month',
            chart: GenericAnalysisChartType::Line,
            allowed: $line,
            metrics: ['count', 'cpr_rate'],
            visualMetricKey: 'cpr_rate',
        ));

        $this->register($this->view(
            key: 'with_physician_rate_by_month',
            title: 'Physician accompaniment rate by month',
            description: 'How does the share of physician-accompanied transports change over time?',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['with_physician', 'month', 'metric:rate', 'audience:clinical'],
            primary: 'month',
            chart: GenericAnalysisChartType::Line,
            allowed: $line,
            metrics: ['count', 'with_physician_rate'],
            visualMetricKey: 'with_physician_rate',
            featured: true,
        ));

        $this->register($this->view(
            key: 'allocations_by_department',
            title: 'Allocations by department',
            description: 'Which departments handle the most allocations?',
            category: AnalysisViewCategory::Operations,
            tags: ['department', 'allocations', 'audience:operations'],
            primary: 'department',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
        ));

        $this->register($this->view(
            key: 'urgency_by_department',
            title: 'Urgency by department',
            description: 'How does urgency mix differ across departments?',
            category: AnalysisViewCategory::Clinical,
            tags: ['department', 'urgency', 'audience:clinical'],
            primary: 'department',
            chart: GenericAnalysisChartType::StackedBar,
            allowed: $stacked,
            secondary: 'urgency',
        ));

        $this->register($this->view(
            key: 'allocations_by_hospital',
            title: 'Allocations by hospital',
            description: 'How are allocations distributed across hospitals?',
            category: AnalysisViewCategory::Hospitals,
            tags: ['hospital', 'allocations', 'audience:operations'],
            primary: 'hospital',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
        ));

        $this->register($this->view(
            key: 'allocations_by_weekday_with_share',
            title: 'Allocations by weekday (with share)',
            description: 'What share of total allocations falls on each weekday?',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['weekday', 'time', 'allocations', 'metric:share', 'audience:operations'],
            primary: 'weekday',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            metrics: ['count', 'percent_of_total'],
        ));

        $this->register($this->view(
            key: 'clinical_rates_by_month',
            title: 'Clinical rates by month',
            description: 'How do resuscitation, cath lab and CPR rates develop over time?',
            category: AnalysisViewCategory::Clinical,
            tags: ['clinical', 'rates', 'month', 'resus', 'cathlab', 'cpr'],
            primary: 'month',
            chart: GenericAnalysisChartType::Line,
            allowed: [GenericAnalysisChartType::Line, GenericAnalysisChartType::GroupedBar, GenericAnalysisChartType::Bar],
            metrics: ['count', 'resus_rate', 'cathlab_rate', 'cpr_rate'],
            visualMetricKey: 'resus_rate',
            seriesMode: AnalysisSeriesMode::ByMetric,
        ));

        $this->register($this->view(
            key: 'hour_weekday_heatmap',
            title: 'Allocations by weekday and hour',
            description: 'When do allocations occur across weekdays and hours?',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['time', 'hour', 'weekday', 'heatmap', 'audience:operations'],
            primary: 'weekday',
            chart: GenericAnalysisChartType::Heatmap,
            allowed: [GenericAnalysisChartType::Heatmap, GenericAnalysisChartType::StackedBar, GenericAnalysisChartType::GroupedBar],
            secondary: 'hour',
        ));

        $this->register($this->view(
            key: 'top_indications',
            title: 'Top indications',
            description: 'Which indications occur most frequently?',
            category: AnalysisViewCategory::Clinical,
            tags: ['indication', 'clinical', 'top'],
            primary: 'indication',
            chart: GenericAnalysisChartType::HorizontalBar,
            allowed: [GenericAnalysisChartType::HorizontalBar, GenericAnalysisChartType::Bar],
        ));

        $this->register($this->view(
            key: 'transport_type_distribution',
            title: 'Transport type distribution',
            description: 'How are allocations distributed across transport types?',
            category: AnalysisViewCategory::Operations,
            tags: ['transport', 'operations'],
            primary: 'transport_type',
            chart: GenericAnalysisChartType::Pie,
            allowed: [GenericAnalysisChartType::Pie, GenericAnalysisChartType::Bar, GenericAnalysisChartType::HorizontalBar],
        ));

        $this->register($this->view(
            key: 'hospitals_by_tier',
            title: 'Hospitals by tier',
            description: 'How many hospitals exist per care tier?',
            category: AnalysisViewCategory::Hospitals,
            tags: ['hospital', 'tier', 'population'],
            primary: 'hospital_tier',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            metrics: ['hospital_count'],
            dataSource: AnalysisDataSource::Hospitals,
        ));

        $this->register($this->view(
            key: 'hospitals_by_size',
            title: 'Hospitals by size',
            description: 'How are hospitals distributed by size class?',
            category: AnalysisViewCategory::Hospitals,
            tags: ['hospital', 'size', 'beds'],
            primary: 'hospital_size',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            metrics: ['hospital_count', 'avg_beds'],
            dataSource: AnalysisDataSource::Hospitals,
        ));

        $this->register($this->view(
            key: 'hospitals_by_tier_compare',
            title: 'Hospitals by tier (participation compare)',
            description: 'Compare participating vs non-participating hospitals per tier.',
            category: AnalysisViewCategory::Hospitals,
            tags: ['hospital', 'tier', 'participation', 'compare'],
            primary: 'hospital_tier',
            chart: GenericAnalysisChartType::GroupedBar,
            allowed: [GenericAnalysisChartType::GroupedBar, GenericAnalysisChartType::Bar, GenericAnalysisChartType::Table],
            metrics: ['hospital_count'],
            dataSource: AnalysisDataSource::Hospitals,
            hospitalPopulationMode: HospitalPopulationMode::Compare,
        ));

        $this->register($this->view(
            key: 'hospital_tier_by_location',
            title: 'Hospital tier by location',
            description: 'Pivot of hospital tiers across location types.',
            category: AnalysisViewCategory::Hospitals,
            tags: ['hospital', 'tier', 'location', 'pivot'],
            primary: 'hospital_tier',
            chart: GenericAnalysisChartType::Table,
            allowed: [GenericAnalysisChartType::Table],
            secondary: 'hospital_location',
            metrics: ['hospital_count'],
            displayMode: AnalysisDisplayMode::PivotTable,
            dataSource: AnalysisDataSource::Hospitals,
        ));

        $this->register($this->view(
            key: 'allocations_per_hospital_tier',
            title: 'Allocations per hospital tier',
            description: 'How many allocations do participating hospitals handle per tier?',
            category: AnalysisViewCategory::Hospitals,
            tags: ['hospital', 'tier', 'allocations'],
            primary: 'hospital_tier',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            metrics: ['total_allocations', 'avg_allocations_per_hospital'],
            dataSource: AnalysisDataSource::Hospitals,
            hospitalPopulationMode: HospitalPopulationMode::Participating,
        ));
    }

    /**
     * @param list<string>                   $tags
     * @param list<string>                   $metrics
     * @param list<GenericAnalysisChartType> $allowed
     */
    private function view(
        string $key,
        string $title,
        string $description,
        AnalysisViewCategory $category,
        array $tags,
        string $primary,
        GenericAnalysisChartType $chart,
        array $allowed,
        ?string $secondary = null,
        array $metrics = [],
        ?string $visualMetricKey = null,
        bool $featured = false,
        bool $includeNullBuckets = false,
        AnalysisSeriesMode $seriesMode = AnalysisSeriesMode::ByDimension,
        AnalysisDisplayMode $displayMode = AnalysisDisplayMode::Chart,
        AnalysisDataSource $dataSource = AnalysisDataSource::Allocations,
        HospitalPopulationMode $hospitalPopulationMode = HospitalPopulationMode::All,
    ): AnalysisViewDefinition {
        return new AnalysisViewDefinition(
            key: $key,
            title: $title,
            description: $description,
            category: $category,
            tags: $tags,
            primaryDimensionKey: $primary,
            secondaryDimensionKey: $secondary,
            metricKeys: $metrics,
            visualMetricKey: $visualMetricKey,
            chartType: $chart,
            allowedChartTypes: [] === $allowed ? [$chart] : $allowed,
            includeNullBuckets: $includeNullBuckets,
            legacyPresetKey: $key,
            isFeatured: $featured,
            seriesMode: $seriesMode,
            displayMode: $displayMode,
            dataSource: $dataSource,
            hospitalPopulationMode: $hospitalPopulationMode,
        );
    }
}
