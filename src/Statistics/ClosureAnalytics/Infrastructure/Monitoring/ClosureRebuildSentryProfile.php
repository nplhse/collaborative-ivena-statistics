<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Monitoring;

use App\Shared\Infrastructure\Monitoring\JobRun\JobRunSentryProfile;

/**
 * Closure rebuild telemetry: run ids, hospital ids, counts and durations only; no names and no closure periods.
 */
final readonly class ClosureRebuildSentryProfile implements JobRunSentryProfile
{
    #[\Override]
    public function transaction(): string
    {
        return ClosureRebuildTelemetry::TRANSACTION;
    }

    #[\Override]
    public function sampledOps(): array
    {
        return [ClosureRebuildTelemetry::OP];
    }

    #[\Override]
    public function spanOpPrefix(): string
    {
        return ClosureRebuildTelemetry::OP;
    }

    #[\Override]
    public function logPrefixes(): array
    {
        return ['closure_rebuild.', 'closure_volume.'];
    }

    #[\Override]
    public function allowedKeys(): array
    {
        return [
            'attempts',
            'duration_ms',
            'error_class',
            'events',
            'full',
            'hospital_id',
            'hospital_ids',
            'hours_written',
            'kind',
            'partitions',
            'pass',
            'passes',
            'peak_memory_bytes',
            'publish_ms',
            'queries',
            'request_ids',
            'requests',
            'retained_bytes',
            'run',
            'run_id',
            'runs',
            'scope',
            'status',
            'threshold_exceeded',
            'thresholds',
            'trigger',
        ];
    }
}
