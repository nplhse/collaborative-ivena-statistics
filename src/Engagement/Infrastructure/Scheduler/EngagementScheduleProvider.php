<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Scheduler;

use App\Engagement\Application\Message\SendMonthlySubmissionRemindersMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/** @psalm-suppress UnusedClass Registered via #[AsSchedule('engagement')]. */
#[AsSchedule('engagement')]
final readonly class EngagementScheduleProvider implements ScheduleProviderInterface
{
    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    #[\Override]
    public function getSchedule(): Schedule
    {
        return new Schedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(
                RecurringMessage::cron(
                    '0 8 * * *',
                    new SendMonthlySubmissionRemindersMessage(),
                    self::TIMEZONE,
                ),
            );
    }
}
