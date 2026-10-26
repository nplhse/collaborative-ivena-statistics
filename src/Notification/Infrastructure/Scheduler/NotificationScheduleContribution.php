<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Scheduler;

use App\Notification\Application\Message\CleanUpNotificationsMessage;
use App\Notification\Application\Message\ReconcileNotificationsMessage;
use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Schedule and job_run names of this context's scheduler jobs (ADR 019).
 *
 * @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface and RecordedJobsInterface.
 */
final readonly class NotificationScheduleContribution implements ScheduleContributionInterface, RecordedJobsInterface
{
    public const string JOB_CLEANUP = 'notification_cleanup';
    public const string JOB_RECONCILE = 'notification_reconcile';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            RecurringMessage::cron('45 2 * * *', new CleanUpNotificationsMessage()),
            RecurringMessage::cron('17 * * * *', new ReconcileNotificationsMessage()),
        ];
    }

    #[\Override]
    public function recordedJobs(): array
    {
        return [
            CleanUpNotificationsMessage::class => self::JOB_CLEANUP,
            ReconcileNotificationsMessage::class => self::JOB_RECONCILE,
        ];
    }
}
