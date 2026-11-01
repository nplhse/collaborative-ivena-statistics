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
            UsageEventName::IMPORT_COMPLETED,
            UsageEventName::IMPORT_REPROCESSED => 2,
            UsageEventName::USER_REGISTERED,
            UsageEventName::USER_BECAME_PARTICIPANT => 0,
            default => 0,
        };
    }

    /**
     * Level 4 ("filters used") was derived from URL parameters and removed in #988;
     * the other levels keep their numbers.
     */
    public function levelForFeatureArea(string $featureArea): int
    {
        return match ($featureArea) {
            'export' => 5,
            'analysis', 'statistics', 'explore', 'import' => 2,
            'dashboard' => 1,
            default => 0,
        };
    }
}
