<?php

declare(strict_types=1);

namespace App\Import\Application\ExportPeriod;

/**
 * Derives the export period of an import from its rows. IVENA files do not carry it.
 *
 * The derivation is conservative: an under-estimated period only keeps dependent
 * closure results provisional longer, an over-estimated one could finalize them
 * while closures are still missing.
 */
final class ExportPeriodDerivation
{
    /**
     * Whole days from the first to the last allocation.
     */
    public static function forAllocations(?\DateTimeImmutable $firstCreatedAt, ?\DateTimeImmutable $lastCreatedAt): ?ExportPeriod
    {
        if (null === $firstCreatedAt || null === $lastCreatedAt) {
            return null;
        }

        return new ExportPeriod(self::floorDay($firstCreatedAt), self::floorDay($lastCreatedAt)->modify('+1 day'));
    }

    /**
     * Whole days from the first closure start to the last closure end.
     * The period is widened to calendar-month boundaries only when allocation imports
     * of the same hospital cover those whole months, which indicates monthly exports.
     *
     * @param list<ExportPeriod> $allocationPeriods
     */
    public static function forClosures(
        ?\DateTimeImmutable $firstStartsAt,
        ?\DateTimeImmutable $lastEndsAt,
        array $allocationPeriods,
    ): ?ExportPeriod {
        if (null === $firstStartsAt || null === $lastEndsAt) {
            return null;
        }
        $startsAt = self::floorDay($firstStartsAt);
        $endsAt = self::ceilDay($lastEndsAt);
        if ($endsAt <= $startsAt) {
            $endsAt = $startsAt->modify('+1 day');
        }
        $days = new ExportPeriod($startsAt, $endsAt);
        $months = new ExportPeriod(self::floorMonth($startsAt), self::ceilMonth($endsAt));
        if (!$months->equals($days) && ExportPeriod::coveredBy($months, $allocationPeriods)) {
            return $months;
        }

        return $days;
    }

    private static function floorDay(\DateTimeImmutable $at): \DateTimeImmutable
    {
        return $at->setTime(0, 0);
    }

    private static function ceilDay(\DateTimeImmutable $at): \DateTimeImmutable
    {
        $floor = self::floorDay($at);

        return $floor == $at ? $floor : $floor->modify('+1 day');
    }

    private static function floorMonth(\DateTimeImmutable $at): \DateTimeImmutable
    {
        return $at->modify('first day of this month')->setTime(0, 0);
    }

    private static function ceilMonth(\DateTimeImmutable $at): \DateTimeImmutable
    {
        $floor = self::floorMonth($at);

        return $floor == $at ? $floor : $floor->modify('first day of next month');
    }
}
