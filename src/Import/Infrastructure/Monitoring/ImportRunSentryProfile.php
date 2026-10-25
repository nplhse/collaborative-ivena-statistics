<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Monitoring;

use App\Shared\Infrastructure\Monitoring\JobRun\JobRunSentryProfile;

/**
 * Import run telemetry: run ids, import and hospital ids, counts and durations only;
 * no file names, hospital names, reasons or row content.
 *
 * @psalm-suppress UnusedClass Registered through the JobRunSentryProfile service tag.
 */
final readonly class ImportRunSentryProfile implements JobRunSentryProfile
{
    #[\Override]
    public function transaction(): string
    {
        return ImportRunTelemetry::TRANSACTION;
    }

    #[\Override]
    public function sampledOps(): array
    {
        return [ImportRunTelemetry::OP_ALLOCATIONS, ImportRunTelemetry::OP_CLOSURES];
    }

    #[\Override]
    public function spanOpPrefix(): string
    {
        return 'import.';
    }

    #[\Override]
    public function logPrefixes(): array
    {
        return ['import.run.summary', 'import.run.threshold_exceeded'];
    }

    #[\Override]
    public function allowedKeys(): array
    {
        return [
            'attempt',
            'chunks',
            'duration_ms',
            'error_class',
            'hospital_id',
            'import_id',
            'import_run_id',
            'job',
            'map_ms',
            'ms',
            'ok',
            'peak_memory_bytes',
            'persist_ms',
            'read_ms',
            'rejected',
            'rows',
            'rows_deduplicated',
            'rows_ok',
            'rows_rejected',
            'rows_total',
            'run_id',
            'status',
            'summed',
            'threshold_exceeded',
            'thresholds',
            'trigger',
        ];
    }
}
