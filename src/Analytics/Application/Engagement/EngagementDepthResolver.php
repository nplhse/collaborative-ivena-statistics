<?php

declare(strict_types=1);

namespace App\Analytics\Application\Engagement;

use App\Analytics\Domain\UsageEventName;

/**
 * Derives engagement depth levels from event names and request signals.
 */
final class EngagementDepthResolver
{
    public function levelForEventName(string $eventName): int
    {
        return match ($eventName) {
            UsageEventName::ANALYSIS_EXPLORER_EXPORTED_CSV => 5,
            UsageEventName::ANALYSIS_EXPLORER_RUN => 3,
            UsageEventName::ANALYSIS_LIBRARY_OPENED,
            UsageEventName::ANALYSIS_EXPLORER_OPENED,
            UsageEventName::ANALYSIS_SAVED_VIEW_OPENED,
            UsageEventName::ANALYSIS_SAVED_VIEW_CREATED,
            UsageEventName::BENCHMARKING_OPENED,
            UsageEventName::EXPLORE_ALLOCATION_OPENED,
            UsageEventName::EXPLORE_HOSPITAL_OPENED,
            UsageEventName::EXPLORE_INDICATION_OPENED,
            UsageEventName::IMPORT_STARTED,
            UsageEventName::IMPORT_COMPLETED => 2,
            UsageEventName::USER_REGISTERED,
            UsageEventName::USER_EMAIL_CONFIRMED,
            UsageEventName::USER_BECAME_PARTICIPANT,
            UsageEventName::ONBOARDING_STEP_COMPLETED => 0,
            default => 0,
        };
    }

    public function levelForFeatureArea(string $featureArea, bool $hasFilters): int
    {
        $base = match ($featureArea) {
            'export' => 5,
            'analysis' => $hasFilters ? 4 : 2,
            'statistics', 'explore', 'import' => 2,
            'dashboard' => 1,
            default => 0,
        };

        if ($hasFilters && \in_array($featureArea, ['statistics', 'analysis'], true)) {
            return max($base, 4);
        }

        return $base;
    }
}
