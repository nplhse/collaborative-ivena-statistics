<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Scheduler;

use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use App\Storage\Application\Message\MeasureStorageMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/** @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface. */
final readonly class StorageScheduleContribution implements ScheduleContributionInterface
{
    private const string TIMEZONE = 'Europe/Berlin';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            RecurringMessage::cron('30 3 * * *', new MeasureStorageMessage(), self::TIMEZONE),
        ];
    }
}
