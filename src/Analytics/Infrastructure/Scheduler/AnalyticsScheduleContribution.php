<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Scheduler;

use App\Analytics\Application\Message\AggregateDailyAnalyticsMessage;
use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Schedule and job_run names of this context's scheduler jobs (ADR 019).
 *
 * @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface and RecordedJobsInterface.
 */
final readonly class AnalyticsScheduleContribution implements ScheduleContributionInterface, RecordedJobsInterface
{
    public const string JOB = 'analytics_aggregation';

    private const string TIMEZONE = 'Europe/Berlin';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            RecurringMessage::cron(
                '15 2 * * *',
                new AggregateDailyAnalyticsMessage(),
                self::TIMEZONE,
            ),
        ];
    }

    #[\Override]
    public function recordedJobs(): array
    {
        return [
            AggregateDailyAnalyticsMessage::class => self::JOB,
        ];
    }
}
