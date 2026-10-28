<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\Allocation\Domain\Enum\AllocationUrgency;

/**
 * Links between the case and fields the pattern tables keep flat (ADR 022, #909).
 *
 * The rules hold for every pattern source, like CoherenceRules; the factors are coarse general
 * knowledge, nudged towards all-hospital aggregates of #874:
 *
 * - accident occasions (traffic, work) belong to injuries (PZC 2xx): other cases draw the rest of
 *   the occasion table; a work accident occasion also sets the work accident flag,
 * - respiratory codes (PZC 31x) draw influenza and COVID five times as often as other cases,
 * - weekend nights (Saturday and Sunday, 0 to 5 h) get 1.3 times the weight of weekday nights,
 * - transport times are mostly rounded to five minutes; urgency 3 cases come from closer by.
 */
final class PatternCouplings
{
    /** Occasions only an injury has, and the PZC prefix of injuries. */
    public const array TRAUMA_OCCASIONS = ['Verkehrsunfall', 'Arbeitsunfall'];

    public const string TRAUMA_PREFIX = '2';

    /** The occasion that also sets `is_work_accident`. */
    public const string WORK_ACCIDENT_OCCASION = 'Arbeitsunfall';

    /** Infection weight factors for respiratory codes. */
    public const array RESPIRATORY_INFECTIONS = ['Influenza' => 5.0, 'V.a. COVID' => 5.0];

    public const string RESPIRATORY_PREFIX = '31';

    public const float WEEKEND_NIGHT_FACTOR = 1.3;

    public const int NIGHT_END_HOUR = 5;

    /** Share of transport times rounded to five minutes, as dispatch times usually are. */
    public const float ROUNDED_SHARE = 0.8;

    /** Transport time factor by urgency; urgencies without a factor keep the segment's times. */
    public const array URGENCY_TRANSPORT_FACTOR = [3 => 0.8];

    /**
     * @param array<string, float> $occasions occasion → weight
     *
     * @return array<string, float> without accident occasions unless the code is an injury
     */
    public static function occasions(array $occasions, ?string $code): array
    {
        if (null !== $code && str_starts_with($code, self::TRAUMA_PREFIX)) {
            return $occasions;
        }

        $other = array_diff_key($occasions, array_flip(self::TRAUMA_OCCASIONS));

        return [] !== $other ? $other : $occasions;
    }

    /**
     * @param array<string, float> $infections infection → weight
     *
     * @return array<string, float>
     */
    public static function infections(array $infections, ?string $code): array
    {
        if (null === $code || !str_starts_with($code, self::RESPIRATORY_PREFIX)) {
            return $infections;
        }

        foreach (self::RESPIRATORY_INFECTIONS as $name => $factor) {
            if (isset($infections[$name])) {
                $infections[$name] *= $factor;
            }
        }

        return $infections;
    }

    /**
     * @param array<int|string, float> $hours hour → weight
     *
     * @return array<int|string, float>
     */
    public static function hours(array $hours, \DateTimeImmutable $day): array
    {
        if ((int) $day->format('N') < 6) {
            return $hours;
        }

        foreach ($hours as $hour => $weight) {
            if ((int) $hour <= self::NIGHT_END_HOUR) {
                $hours[$hour] = $weight * self::WEEKEND_NIGHT_FACTOR;
            }
        }

        return $hours;
    }

    /**
     * @param bool $rounded whether this time is one of the rounded ones (drawn with ROUNDED_SHARE)
     */
    public static function transportMinutes(int $minutes, AllocationUrgency $urgency, bool $rounded): int
    {
        $minutes = (int) round((float) $minutes * (self::URGENCY_TRANSPORT_FACTOR[$urgency->value] ?? 1.0));
        if ($rounded) {
            $minutes = 5 * (int) round($minutes / 5);
        }

        return max($rounded ? 5 : 1, $minutes);
    }
}
