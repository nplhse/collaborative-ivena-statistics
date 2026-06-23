<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use Yasumi\ProviderInterface;
use Yasumi\Yasumi;

final readonly class FirstWorkingDayResolver
{
    private const string TIMEZONE = 'Europe/Berlin';

    public function forMonth(\DateTimeImmutable $dateInMonth): \DateTimeImmutable
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $inMonth = $dateInMonth->setTimezone($tz);
        $year = (int) $inMonth->format('Y');
        $holidays = Yasumi::create('Germany', $year);

        $cursor = $inMonth->modify('first day of this month 00:00:00');
        $month = (int) $cursor->format('n');

        while ((int) $cursor->format('n') === $month) {
            if ($this->isWorkingDay($cursor, $holidays)) {
                return $cursor->setTime(8, 0);
            }

            $cursor = $cursor->modify('+1 day');
        }

        throw new \LogicException(sprintf('No working day found in month %04d-%02d.', $year, $month));
    }

    private function isWorkingDay(\DateTimeImmutable $date, ProviderInterface $holidays): bool
    {
        $weekday = (int) $date->format('N');

        if ($weekday >= 6) {
            return false;
        }

        return !$holidays->isHoliday($date);
    }
}
