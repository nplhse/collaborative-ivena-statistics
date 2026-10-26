<?php

declare(strict_types=1);

namespace App\Admin\Application\Service\JobRun;

use App\Analytics\Infrastructure\Scheduler\AnalyticsScheduleContribution;
use App\Engagement\Infrastructure\Scheduler\ReminderScheduleContribution;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Infrastructure\Monitoring\ImportRunTelemetry;
use App\Kpi\Infrastructure\Scheduler\KpiScheduleContribution;
use App\Notification\Infrastructure\Scheduler\NotificationScheduleContribution;
use App\Statistics\ClosureAnalytics\Infrastructure\Monitoring\ClosureRebuildTelemetry;
use App\Statistics\Infrastructure\Monitoring\AllocationProjectionRunTelemetry;
use App\Storage\Infrastructure\Scheduler\StorageScheduleContribution;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Known values of job_run (ADR 018, ADR 019) with their admin labels and badge colors.
 * job_run stores plain strings, so a value missing here is shown as it is instead of failing.
 */
final class JobRunCatalog
{
    public const string FORMAT_COUNT = 'count';
    public const string FORMAT_MS = 'ms';
    public const string FORMAT_BOOL = 'bool';
    public const string FORMAT_HOSPITALS = 'hospitals';
    public const string FORMAT_TEXT = 'text';

    /** @var list<string> */
    public const array JOBS = [
        ImportRunTelemetry::JOB_ALLOCATIONS,
        ImportRunTelemetry::JOB_CLOSURES,
        AllocationProjectionRunTelemetry::JOB,
        AllocationProjectionRunTelemetry::JOB_FULL,
        ClosureRebuildTelemetry::JOB,
        KpiScheduleContribution::JOB,
        ReminderScheduleContribution::JOB,
        AnalyticsScheduleContribution::JOB,
        NotificationScheduleContribution::JOB_CLEANUP,
        NotificationScheduleContribution::JOB_RECONCILE,
        StorageScheduleContribution::JOB,
    ];

    /** Status => badge type. */
    public const array STATUSES = [
        'running' => 'info',
        'succeeded' => 'success',
        'skipped' => 'light',
        'partial' => 'warning',
        'failed' => 'danger',
        'retrying' => 'warning',
        'superseded' => 'secondary',
        'abandoned' => 'dark',
    ];

    /** Trigger => badge type; user actions stand out, automatic runs stay quiet. Unknown triggers get "secondary". */
    public const array TRIGGER_BADGES = [
        'messenger' => 'secondary',
        'scheduler' => 'secondary',
        'console' => 'dark',
        'upload' => 'primary',
        'reprocess' => 'info',
        'bulk_reprocess' => 'info',
        'batch_requeue' => 'info',
        'unknown' => 'light',
    ];

    /** @var list<string> */
    public const array THRESHOLDS = ['slow', 'long_run', 'memory', 'passes', 'attempts'];

    /**
     * Metrics keys per job in display order (key => format). Keys not listed are shown after them as text.
     *
     * @var array<string, array<string, string>>
     */
    public const array METRICS = [
        ImportRunTelemetry::JOB_ALLOCATIONS => [
            'rows_total' => self::FORMAT_COUNT,
            'rows_ok' => self::FORMAT_COUNT,
            'rows_rejected' => self::FORMAT_COUNT,
            'rows_deduplicated' => self::FORMAT_COUNT,
            'chunks' => self::FORMAT_COUNT,
            'read_ms' => self::FORMAT_MS,
            'map_ms' => self::FORMAT_MS,
            'persist_ms' => self::FORMAT_MS,
        ],
        ImportRunTelemetry::JOB_CLOSURES => [
            'rows_total' => self::FORMAT_COUNT,
            'rows_ok' => self::FORMAT_COUNT,
            'rows_rejected' => self::FORMAT_COUNT,
            'chunks' => self::FORMAT_COUNT,
            'read_ms' => self::FORMAT_MS,
            'map_ms' => self::FORMAT_MS,
            'persist_ms' => self::FORMAT_MS,
        ],
        AllocationProjectionRunTelemetry::JOB => [
            'imports' => self::FORMAT_COUNT,
            'rows_deleted' => self::FORMAT_COUNT,
            'rows_written' => self::FORMAT_COUNT,
            'mv_refreshed' => self::FORMAT_BOOL,
            'change_detection_ms' => self::FORMAT_MS,
            'delete_ms' => self::FORMAT_MS,
            'insert_ms' => self::FORMAT_MS,
            'closure_request_ms' => self::FORMAT_MS,
            'mv_refresh_ms' => self::FORMAT_MS,
        ],
        AllocationProjectionRunTelemetry::JOB_FULL => [
            'imports' => self::FORMAT_COUNT,
            'rows_deleted' => self::FORMAT_COUNT,
            'rows_written' => self::FORMAT_COUNT,
            'mv_refreshed' => self::FORMAT_BOOL,
            'truncate_ms' => self::FORMAT_MS,
            'rebuild_imports_ms' => self::FORMAT_MS,
            'closure_request_ms' => self::FORMAT_MS,
            'mv_refresh_ms' => self::FORMAT_MS,
        ],
        ClosureRebuildTelemetry::JOB => [
            'full' => self::FORMAT_BOOL,
            'hospital_ids' => self::FORMAT_HOSPITALS,
            'passes' => self::FORMAT_COUNT,
            'events' => self::FORMAT_COUNT,
            'partitions' => self::FORMAT_COUNT,
            'hours_written' => self::FORMAT_COUNT,
            'publish_ms' => self::FORMAT_MS,
            'requests' => self::FORMAT_COUNT,
            'queries' => self::FORMAT_COUNT,
            'reference_window' => self::FORMAT_TEXT,
        ],
        KpiScheduleContribution::JOB => [
            'skip_reason' => self::FORMAT_TEXT,
            'days_processed' => self::FORMAT_COUNT,
            'days_with_data' => self::FORMAT_COUNT,
            'rows' => self::FORMAT_COUNT,
        ],
        ReminderScheduleContribution::JOB => [
            'skip_reason' => self::FORMAT_TEXT,
            'hospitals' => self::FORMAT_COUNT,
            'queued' => self::FORMAT_COUNT,
            'skipped' => self::FORMAT_COUNT,
        ],
        AnalyticsScheduleContribution::JOB => [
            'skip_reason' => self::FORMAT_TEXT,
            'days_processed' => self::FORMAT_COUNT,
            'raw_requests' => self::FORMAT_COUNT,
            'raw_events' => self::FORMAT_COUNT,
            'aggregate_rows' => self::FORMAT_COUNT,
            'raw_rows_deleted' => self::FORMAT_COUNT,
        ],
        NotificationScheduleContribution::JOB_CLEANUP => [
            'skip_reason' => self::FORMAT_TEXT,
            'deleted_notifications' => self::FORMAT_COUNT,
            'deleted_deduplication_keys' => self::FORMAT_COUNT,
            'corrected_recipients' => self::FORMAT_COUNT,
        ],
        NotificationScheduleContribution::JOB_RECONCILE => [
            'skip_reason' => self::FORMAT_TEXT,
            'published' => self::FORMAT_COUNT,
            'deliveries_resumed' => self::FORMAT_COUNT,
        ],
        StorageScheduleContribution::JOB => [
            'skip_reason' => self::FORMAT_TEXT,
            'level' => self::FORMAT_TEXT,
            'failures' => self::FORMAT_COUNT,
        ],
    ];

    /**
     * Translation keys for every metrics key listed in METRICS.
     */
    public const array METRIC_LABELS = [
        'rows_total', 'rows_ok', 'rows_rejected', 'rows_deduplicated', 'chunks', 'read_ms', 'map_ms', 'persist_ms',
        'imports', 'rows_deleted', 'rows_written', 'mv_refreshed', 'change_detection_ms', 'delete_ms', 'insert_ms',
        'closure_request_ms', 'mv_refresh_ms', 'truncate_ms', 'rebuild_imports_ms',
        'full', 'hospital_ids', 'passes', 'events', 'partitions', 'hours_written', 'publish_ms', 'requests', 'queries',
        'reference_window',
        'skip_reason', 'days_processed', 'days_with_data', 'rows', 'hospitals', 'queued', 'skipped', 'raw_requests',
        'raw_events', 'aggregate_rows', 'raw_rows_deleted', 'deleted_notifications', 'deleted_deduplication_keys',
        'corrected_recipients', 'published', 'deliveries_resumed', 'level', 'failures',
    ];

    /**
     * @return list<string>
     */
    public static function triggers(): array
    {
        $triggers = ['messenger', 'scheduler', 'console'];
        foreach (ImportRunTrigger::cases() as $trigger) {
            $triggers[] = $trigger->value;
        }
        $triggers[] = 'unknown';

        return array_values(array_unique($triggers));
    }

    /**
     * Choices for fields and filters: submitted value => label. The current value is added when it is unknown.
     *
     * @return array<string, TranslatableInterface|string>
     */
    public static function jobChoices(?string $current = null): array
    {
        return self::choices('job', self::JOBS, $current);
    }

    /**
     * @return array<string, TranslatableInterface|string>
     */
    public static function statusChoices(?string $current = null): array
    {
        return self::choices('status', array_keys(self::STATUSES), $current);
    }

    /**
     * @return array<string, TranslatableInterface|string>
     */
    public static function triggerChoices(?string $current = null): array
    {
        return self::choices('trigger', self::triggers(), $current);
    }

    public static function statusBadge(string $status): string
    {
        return self::STATUSES[$status] ?? 'secondary';
    }

    public static function thresholdLabel(string $kind): TranslatableInterface|string
    {
        return \in_array($kind, self::THRESHOLDS, true) ? new TranslatableMessage('admin.job_run.threshold.'.$kind, domain: 'admin') : $kind;
    }

    public static function metricLabel(string $key): TranslatableInterface|string
    {
        return \in_array($key, self::METRIC_LABELS, true) ? new TranslatableMessage('admin.job_run.metric.'.$key, domain: 'admin') : $key;
    }

    /**
     * @return array<string, string>
     */
    public static function metricFormats(string $job): array
    {
        return self::METRICS[$job] ?? [];
    }

    /**
     * @param list<string> $known
     *
     * @return array<string, TranslatableInterface|string>
     */
    private static function choices(string $kind, array $known, ?string $current): array
    {
        $choices = [];
        foreach ($known as $value) {
            $choices[$value] = new TranslatableMessage('admin.job_run.'.$kind.'.'.$value, domain: 'admin');
        }
        if (null !== $current && '' !== $current && !isset($choices[$current])) {
            $choices[$current] = $current;
        }

        return $choices;
    }
}
