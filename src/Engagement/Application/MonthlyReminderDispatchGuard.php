<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use Psr\Clock\ClockInterface;

final readonly class MonthlyReminderDispatchGuard
{
    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private FirstWorkingDayResolver $firstWorkingDayResolver,
        private ClockInterface $clock,
    ) {
    }

    public function shouldDispatchToday(?\DateTimeImmutable $today = null): bool
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $today = ($today ?? $this->clock->now())->setTimezone($tz);
        $firstWorkingDay = $this->firstWorkingDayResolver->forMonth($today);

        return $today->format('Y-m-d') === $firstWorkingDay->format('Y-m-d');
    }
}
