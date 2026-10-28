<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\DataFixtures\Pattern\Package\OptionalShares;
use App\DataFixtures\Pattern\Package\SegmentPattern;

/**
 * Translates between the distributions the sampler reads (DistributionPattern) and a segment of a
 * pattern package. Both directions keep the order of every table, so sampling draws the same values.
 */
final class PatternPackageMapper
{
    private const string PRESENT = '_present';

    /**
     * @param array<string, mixed> $distributions the distributions of a DistributionPattern
     */
    public static function segmentFromDistributions(array $distributions): SegmentPattern
    {
        return new SegmentPattern(
            indications: self::optional($distributions['indication_normalized'] ?? []),
            occasions: self::table($distributions['occasion'] ?? []),
            assignments: self::table($distributions['assignment'] ?? []),
            infections: self::optional($distributions['infection'] ?? []),
            secondaryTransports: self::optional($distributions['secondary_transport'] ?? []),
            transportTypes: self::table($distributions['transport_type'] ?? []),
            hours: self::table($distributions['hour_of_day'] ?? []),
            flags: self::table($distributions['flags'] ?? []),
            transportTimeMinutes: array_map(floatval(...), array_intersect_key(self::table($distributions['transport_time_minutes'] ?? []), array_flip(['p25', 'p50', 'p75', 'p90']))),
        );
    }

    /**
     * The distributions of a DistributionPattern, in the order DefaultDistributionPatterns builds them.
     *
     * @return array<string, mixed>
     */
    public static function distributionsOf(SegmentPattern $segment): array
    {
        return [
            'transport_type' => $segment->transportTypes,
            'hour_of_day' => $segment->hours,
            'assignment' => $segment->assignments,
            'occasion' => $segment->occasions,
            'infection' => [self::PRESENT => $segment->infections->present] + $segment->infections->shares,
            'secondary_transport' => [self::PRESENT => $segment->secondaryTransports->present] + $segment->secondaryTransports->shares,
            'indication_normalized' => [self::PRESENT => $segment->indications->present] + $segment->indications->shares,
            'flags' => $segment->flags,
            'transport_time_minutes' => $segment->transportTimeMinutes,
        ];
    }

    private static function optional(mixed $distribution): OptionalShares
    {
        $table = self::table($distribution);
        $present = $table[self::PRESENT] ?? 1.0;
        unset($table[self::PRESENT]);

        return new OptionalShares($present, $table);
    }

    /**
     * @return array<array-key, float>
     */
    private static function table(mixed $distribution): array
    {
        if (!\is_array($distribution)) {
            return [];
        }

        return array_map(floatval(...), array_filter($distribution, \is_numeric(...)));
    }
}
