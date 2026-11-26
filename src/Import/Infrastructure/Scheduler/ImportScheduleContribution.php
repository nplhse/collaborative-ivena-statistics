<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Scheduler;

use App\Import\Application\Message\PurgePendingImportUploadsMessage;
use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Schedule and job_run names of this context's scheduler jobs (ADR 019).
 *
 * @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface and RecordedJobsInterface.
 */
final readonly class ImportScheduleContribution implements ScheduleContributionInterface, RecordedJobsInterface
{
    public const string PENDING_UPLOAD_PURGE_JOB = 'import_pending_upload_purge';

    private const string TIMEZONE = 'Europe/Berlin';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            // Hourly at :25.
            RecurringMessage::cron('25 * * * *', new PurgePendingImportUploadsMessage(), self::TIMEZONE),
        ];
    }

    #[\Override]
    public function recordedJobs(): array
    {
        return [
            PurgePendingImportUploadsMessage::class => self::PENDING_UPLOAD_PURGE_JOB,
        ];
    }
}
