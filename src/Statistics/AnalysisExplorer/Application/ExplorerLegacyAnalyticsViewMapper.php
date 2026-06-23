<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

final class ExplorerLegacyAnalyticsViewMapper
{
    /** @var array<string, string> */
    private const array VIEW_KEY_TO_SLUG = [
        'allocations_by_month' => 'allocations-over-time',
        'allocations_by_hour' => 'day-time-bucket-distribution',
        'allocations_by_weekday' => 'allocations-by-weekday',
        'age_group_distribution' => 'age-group-distribution',
        'gender_distribution' => 'gender-distribution',
        'urgency_by_month' => 'urgency-over-time',
    ];

    public function slugForLegacyViewKey(string $viewKey): ?string
    {
        $viewKey = trim($viewKey);

        return self::VIEW_KEY_TO_SLUG[$viewKey] ?? null;
    }
}
