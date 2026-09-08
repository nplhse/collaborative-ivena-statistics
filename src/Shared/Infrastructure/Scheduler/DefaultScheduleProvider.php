<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Scheduler;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/** @psalm-suppress UnusedClass Registered via #[AsSchedule]. */
#[AsSchedule]
final readonly class DefaultScheduleProvider implements ScheduleProviderInterface
{
    /**
     * @param iterable<ScheduleContributionInterface> $contributions
     */
    public function __construct(
        private CacheInterface $cache,
        #[AutowireIterator('app.schedule_contribution')]
        private iterable $contributions,
    ) {
    }

    #[\Override]
    public function getSchedule(): Schedule
    {
        $schedule = new Schedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);

        foreach ($this->contributions as $contribution) {
            foreach ($contribution->recurringMessages() as $message) {
                $schedule->add($message);
            }
        }

        return $schedule;
    }
}
