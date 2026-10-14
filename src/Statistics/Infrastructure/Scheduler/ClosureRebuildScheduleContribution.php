<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Scheduler;

use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use App\Statistics\Application\Message\RebuildClosureVolumeProjection;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Picks up closure rebuild requests nobody consumes: requests of a killed run and
 * requests that arrived while a console rebuild held the lock.
 *
 * @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface.
 */
final readonly class ClosureRebuildScheduleContribution implements ScheduleContributionInterface
{
    private const string TIMEZONE = 'Europe/Berlin';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            RecurringMessage::cron(
                '40 * * * *',
                new RebuildClosureVolumeProjection(waitForLock: false),
                self::TIMEZONE,
            ),
        ];
    }
}
