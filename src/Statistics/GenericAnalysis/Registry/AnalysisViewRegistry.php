<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Registry;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewDefinition;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewCategory;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
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
            description: 'Monthly allocation volume over time.',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['time', 'month', 'allocations'],
            primary: 'month',
            chart: GenericAnalysisChartType::Line,
            allowed: $line,
            featured: true,
        ));

        $this->register($this->view(
            key: 'allocations_by_weekday',
            title: 'Allocations by weekday',
            description: 'How allocations distribute across weekdays.',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['time', 'weekday', 'allocations'],
            primary: 'weekday',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            featured: true,
        ));

        $this->register($this->view(
            key: 'allocations_by_hour',
            title: 'Allocations by hour',
            description: 'Hourly allocation patterns across the day.',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['time', 'hour', 'allocations'],
            primary: 'hour',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
        ));

        $this->register($this->view(
            key: 'urgency_by_month',
            title: 'Urgency by month',
            description: 'Urgency levels broken down by month.',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['time', 'month', 'urgency'],
            primary: 'month',
            chart: GenericAnalysisChartType::StackedBar,
            allowed: $stacked,
            secondary: 'urgency',
            featured: true,
        ));

        $this->register($this->view(
            key: 'gender_distribution',
            title: 'Gender distribution',
            description: 'Patient gender distribution in allocations.',
            category: AnalysisViewCategory::Patients,
            tags: ['gender', 'patients'],
            primary: 'gender',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            featured: true,
        ));

        $this->register($this->view(
            key: 'resus_by_hour',
            title: 'Resuscitation by hour',
            description: 'Resuscitation cases by hour of day.',
            category: AnalysisViewCategory::Operations,
            tags: ['resus', 'time', 'hour'],
            primary: 'hour',
            chart: GenericAnalysisChartType::StackedBar,
            allowed: $stacked,
            secondary: 'resus',
            featured: true,
        ));

        $this->register($this->view(
            key: 'age_group_distribution',
            title: 'Age group distribution',
            description: 'Patient age group distribution.',
            category: AnalysisViewCategory::Patients,
            tags: ['age', 'patients'],
            primary: 'age_group',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
        ));

        $this->register($this->view(
            key: 'allocations_by_hospital_cohort',
            title: 'Allocations by hospital cohort',
            description: 'Allocations grouped by hospital cohort.',
            category: AnalysisViewCategory::Hospitals,
            tags: ['hospital', 'cohort', 'allocations'],
            primary: 'hospital_cohort',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
        ));

        $this->register($this->view(
            key: 'urgency_by_hospital_cohort',
            title: 'Urgency by hospital cohort',
            description: 'Urgency levels across hospital cohorts.',
            category: AnalysisViewCategory::Hospitals,
            tags: ['hospital', 'urgency', 'cohort'],
            primary: 'hospital_cohort',
            chart: GenericAnalysisChartType::StackedBar,
            allowed: $stacked,
            secondary: 'urgency',
        ));

        $this->register($this->view(
            key: 'allocations_by_month_with_share',
            title: 'Allocations by month (with share)',
            description: 'Monthly allocations with percentage of total.',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['time', 'month', 'comparison', 'allocations'],
            primary: 'month',
            chart: GenericAnalysisChartType::Line,
            allowed: $line,
            metrics: ['count', 'percent_of_total'],
        ));

        $this->register($this->view(
            key: 'urgency_distribution_with_share',
            title: 'Urgency distribution (with share)',
            description: 'Urgency distribution with share of total.',
            category: AnalysisViewCategory::Clinical,
            tags: ['urgency', 'comparison'],
            primary: 'urgency',
            chart: GenericAnalysisChartType::Bar,
            allowed: $bar,
            metrics: ['count', 'percent_of_total'],
        ));

        $this->register($this->view(
            key: 'urgency_by_month_with_bucket_share',
            title: 'Urgency by month (bucket share)',
            description: 'Monthly urgency with share within each month.',
            category: AnalysisViewCategory::TimeAndTrends,
            tags: ['time', 'month', 'urgency', 'comparison'],
            primary: 'month',
            chart: GenericAnalysisChartType::StackedBar,
            allowed: $stacked,
            secondary: 'urgency',
            metrics: ['count', 'percent_of_bucket'],
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
        bool $featured = false,
        bool $includeNullBuckets = false,
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
            chartType: $chart,
            allowedChartTypes: [] === $allowed ? [$chart] : $allowed,
            includeNullBuckets: $includeNullBuckets,
            legacyPresetKey: $key,
            isFeatured: $featured,
        );
    }
}
