<?php

declare(strict_types=1);

namespace App\Engagement\Application;

final readonly class MonthlyReminderDispatchGuard
{
    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private FirstWorkingDayResolver $firstWorkingDayResolver,
    ) {
    }

    public function shouldDispatchToday(?\DateTimeImmutable $today = null): bool
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $today = ($today ?? new \DateTimeImmutable('now', $tz))->setTimezone($tz);
        $firstWorkingDay = $this->firstWorkingDayResolver->forMonth($today);

        return $today->format('Y-m-d') === $firstWorkingDay->format('Y-m-d');
    }
}
