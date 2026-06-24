<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

final class ExplorerLegacyAnalyticsViewMapper
{
    /** @var array<string, string> */
    private const array VIEW_KEY_TO_SLUG = [
        'allocations_by_month' => 'allocations-over-time',
        'allocations_by_hour' => 'allocations-by-hour',
        'allocations_by_weekday' => 'allocations-by-weekday',
        'allocations_weekday_daytime_heatmap' => 'allocations-weekday-by-day-time-heatmap',
        'allocations_weekday_shift_heatmap' => 'allocations-weekday-by-shift-heatmap',
        'age_group_distribution' => 'age-group-distribution',
        'gender_distribution' => 'gender-distribution',
        'urgency_by_month' => 'urgency-over-time',
        'clinical_resources_comparison' => 'overview-clinical-resources',
        'clinical_features_comparison' => 'overview-clinical-features',
        'transport_time_distribution_by_urgency' => 'transport-time-distribution-by-urgency',
        'transport_time_bucket_distribution' => 'transport-time-bucket-distribution',
    ];

    public function slugForLegacyViewKey(string $viewKey): ?string
    {
        $viewKey = trim($viewKey);

        return self::VIEW_KEY_TO_SLUG[$viewKey] ?? null;
    }
}
