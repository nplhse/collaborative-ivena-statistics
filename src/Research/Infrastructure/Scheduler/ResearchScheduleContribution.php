<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Scheduler;

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

    private const string TIMEZONE = 'Europe/Berlin';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            // Every 15 minutes: deadlines are days long, a quarter of an hour late is fine.
            RecurringMessage::cron('*/15 * * * *', new EvaluateDataRequestDeadlinesMessage(), self::TIMEZONE),
        ];
    }

    #[\Override]
    public function recordedJobs(): array
    {
        return [
            EvaluateDataRequestDeadlinesMessage::class => self::DEADLINES_JOB,
        ];
    }
}
