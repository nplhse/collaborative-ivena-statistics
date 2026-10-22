<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Scheduler;

use App\Notification\Application\Message\CleanUpNotificationsMessage;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/** @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface. */
final readonly class NotificationScheduleContribution implements ScheduleContributionInterface
{
    #[\Override]
    public function recurringMessages(): array
    {
        return [
            RecurringMessage::cron('45 2 * * *', new CleanUpNotificationsMessage()),
        ];
    }
}
