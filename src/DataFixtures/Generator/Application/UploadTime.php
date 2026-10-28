<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use Random\Randomizer;

/**
 * When a synthetic import was "uploaded": one to three days after its last day of data, during office
 * hours, never after the last day of the generated period.
 *
 * Real IVENA exports are uploaded after the month (or event) they cover. The upload time is the import's
 * `created_at`, which the import list and the KPI aggregation (`kpi_daily`) are keyed by; with the load
 * time instead, every import would fall on the same day.
 */
final class UploadTime
{
    private const int MIN_DAYS_AFTER = 1;
    private const int MAX_DAYS_AFTER = 3;
    private const int FIRST_HOUR = 7;
    private const int LAST_HOUR = 17;

    /**
     * @param string $lastDataDay last day with data, as Y-m-d
     */
    public static function after(string $lastDataDay, \DateTimeImmutable $lastDay, Randomizer $random): \DateTimeImmutable
    {
        $day = new \DateTimeImmutable($lastDataDay.' 00:00:00')
            ->modify(sprintf('+%d days', $random->getInt(self::MIN_DAYS_AFTER, self::MAX_DAYS_AFTER)));
        $latest = new \DateTimeImmutable($lastDay->format('Y-m-d').' 00:00:00');
        if ($day > $latest) {
            $day = $latest;
        }

        return $day->setTime($random->getInt(self::FIRST_HOUR, self::LAST_HOUR), $random->getInt(0, 59));
    }
}
