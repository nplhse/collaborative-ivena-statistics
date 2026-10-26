<?php

declare(strict_types=1);

namespace App\Kpi\Infrastructure\Scheduler;

use App\Kpi\Application\Message\GenerateDailyKpisMessage;
use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Schedule and job_run names of this context's scheduler jobs (ADR 019).
 *
 * @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface and RecordedJobsInterface.
 */
final readonly class KpiScheduleContribution implements ScheduleContributionInterface, RecordedJobsInterface
{
    public const string JOB = 'kpi_daily';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            RecurringMessage::cron(
                '0 */6 * * *',
                new GenerateDailyKpisMessage(),
            ),
        ];
    }

    #[\Override]
    public function recordedJobs(): array
    {
        return [
            GenerateDailyKpisMessage::class => self::JOB,
        ];
    }
}
