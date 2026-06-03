<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisPreset;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisPresetException;

final class AnalysisPresetRegistry
{
    /** @var array<string, AnalysisPreset> */
    private array $presets = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function get(string $key): AnalysisPreset
    {
        return $this->presets[$key] ?? throw UnknownAnalysisPresetException::forKey($key);
    }

    public function has(string $key): bool
    {
        return isset($this->presets[$key]);
    }

    /**
     * @return list<AnalysisPreset>
     */
    public function all(): array
    {
        return array_values($this->presets);
    }

    /**
     * @return list<AnalysisPreset>
     */
    public function selectable(): array
    {
        return array_values(array_filter(
            $this->presets,
            static fn (AnalysisPreset $preset): bool => 'custom' !== $preset->key,
        ));
    }

    private function register(AnalysisPreset $preset): void
    {
        $this->presets[$preset->key] = $preset;
    }

    private function registerDefaults(): void
    {
        $this->register(new AnalysisPreset(
            key: 'allocations_by_month',
            title: 'Allocations by month',
            primaryDimensionKey: 'month',
        ));
        $this->register(new AnalysisPreset(
            key: 'allocations_by_weekday',
            title: 'Allocations by weekday',
            primaryDimensionKey: 'weekday',
        ));
        $this->register(new AnalysisPreset(
            key: 'allocations_by_hour',
            title: 'Allocations by hour',
            primaryDimensionKey: 'hour',
        ));
        $this->register(new AnalysisPreset(
            key: 'urgency_by_month',
            title: 'Urgency by month',
            primaryDimensionKey: 'month',
            seriesDimensionKey: 'urgency',
        ));
        $this->register(new AnalysisPreset(
            key: 'gender_distribution',
            title: 'Gender distribution',
            primaryDimensionKey: 'gender',
            includeNullBuckets: false,
        ));
        $this->register(new AnalysisPreset(
            key: 'resus_by_hour',
            title: 'Resuscitation by hour',
            primaryDimensionKey: 'hour',
            seriesDimensionKey: 'resus',
        ));
        $this->register(new AnalysisPreset(
            key: 'age_group_distribution',
            title: 'Age group distribution',
            primaryDimensionKey: 'age_group',
        ));
        $this->register(new AnalysisPreset(
            key: 'allocations_by_hospital_cohort',
            title: 'Allocations by hospital cohort',
            primaryDimensionKey: 'hospital_cohort',
        ));
        $this->register(new AnalysisPreset(
            key: 'urgency_by_hospital_cohort',
            title: 'Urgency by hospital cohort',
            primaryDimensionKey: 'hospital_cohort',
            seriesDimensionKey: 'urgency',
        ));
        $this->register(new AnalysisPreset(
            key: 'allocations_by_month_with_share',
            title: 'Allocations by month (with share)',
            primaryDimensionKey: 'month',
            metricKeys: ['count', 'percent_of_total'],
        ));
        $this->register(new AnalysisPreset(
            key: 'urgency_distribution_with_share',
            title: 'Urgency distribution (with share)',
            primaryDimensionKey: 'urgency',
            metricKeys: ['count', 'percent_of_total'],
        ));
        $this->register(new AnalysisPreset(
            key: 'urgency_by_month_with_bucket_share',
            title: 'Urgency by month (bucket share)',
            primaryDimensionKey: 'month',
            seriesDimensionKey: 'urgency',
            metricKeys: ['count', 'percent_of_bucket'],
        ));
        $this->register(new AnalysisPreset(
            key: 'custom',
            title: 'Custom analysis',
            primaryDimensionKey: 'month',
        ));
    }
}
