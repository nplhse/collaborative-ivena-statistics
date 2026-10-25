<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Monitoring;

use App\Shared\Infrastructure\Monitoring\JobRun\JobRunSentryProfile;

/**
 * Allocation stats projection rebuild telemetry: run, import and hospital ids, counts and durations only.
 *
 * @psalm-suppress UnusedClass Registered through the JobRunSentryProfile service tag.
 */
final readonly class AllocationProjectionSentryProfile implements JobRunSentryProfile
{
    #[\Override]
    public function transaction(): string
    {
        return AllocationProjectionRunTelemetry::TRANSACTION;
    }

    #[\Override]
    public function sampledOps(): array
    {
        return [AllocationProjectionRunTelemetry::OP];
    }

    #[\Override]
    public function spanOpPrefix(): string
    {
        return 'projection.';
    }

    #[\Override]
    public function logPrefixes(): array
    {
        return ['projection_rebuild.'];
    }

    #[\Override]
    public function allowedKeys(): array
    {
        return [
            'change_detection_ms',
            'closure_request_ms',
            'delete_ms',
            'duration_ms',
            'error_class',
            'groups',
            'hospital_id',
            'import_id',
            'insert_ms',
            'mv_refresh_ms',
            'mv_refreshed',
            'peak_memory_bytes',
            'rows_deleted',
            'rows_written',
            'run_id',
            'status',
            'threshold_exceeded',
            'thresholds',
            'trigger',
        ];
    }
}
