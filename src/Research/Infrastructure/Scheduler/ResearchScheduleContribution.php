<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Scheduler;

use App\Research\Application\Message\CleanUpDataExportsMessage;
use App\Research\Application\Message\EvaluateDataRequestDeadlinesMessage;
use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Schedule and job_run names of the Research context (ADR 019, ADR 045).
 *
 * @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface and RecordedJobsInterface.
 */
final readonly class ResearchScheduleContribution implements ScheduleContributionInterface, RecordedJobsInterface
{
    public const string DEADLINES_JOB = 'research_data_request_deadlines';
    public const string EXPORT_CLEANUP_JOB = 'research_export_cleanup';

    private const string TIMEZONE = 'Europe/Berlin';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            // Every 15 minutes: deadlines are days long, a quarter of an hour late is fine.
            RecurringMessage::cron('*/15 * * * *', new EvaluateDataRequestDeadlinesMessage(), self::TIMEZONE),
            // Daily at 04:50: the download route already refuses expired exports; this only frees the space.
            RecurringMessage::cron('50 4 * * *', new CleanUpDataExportsMessage(), self::TIMEZONE),
        ];
    }

    #[\Override]
    public function recordedJobs(): array
    {
        return [
            EvaluateDataRequestDeadlinesMessage::class => self::DEADLINES_JOB,
            CleanUpDataExportsMessage::class => self::EXPORT_CLEANUP_JOB,
        ];
    }
}
