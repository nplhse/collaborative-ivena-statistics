<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Scheduler;

use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use App\User\Application\Message\AggregateLoginFailuresMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Schedule and job_run names of this context's scheduler jobs (ADR 019).
 *
 * @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface and RecordedJobsInterface.
 */
final readonly class UserScheduleContribution implements ScheduleContributionInterface, RecordedJobsInterface
{
    public const string LOGIN_FAILURE_AGGREGATION_JOB = 'login_failure_aggregation';

    private const string TIMEZONE = 'Europe/Berlin';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            // Daily 02:25.
            RecurringMessage::cron('25 2 * * *', new AggregateLoginFailuresMessage(), self::TIMEZONE),
        ];
    }

    #[\Override]
    public function recordedJobs(): array
    {
        return [
            AggregateLoginFailuresMessage::class => self::LOGIN_FAILURE_AGGREGATION_JOB,
        ];
    }
}
