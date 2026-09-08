<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

/**
 * Maps recorded allocation duration onto 5-minute destination-isochrone bands.
 */
final class IsochroneTravelBand
{
    public const int INTERVAL_MINUTES = 5;
    public const int MAX_MINUTES = 50;

    /**
     * @psalm-pure
     */
    public static function minutesBetween(?\DateTimeImmutable $createdAt, ?\DateTimeImmutable $arrivalAt): ?int
    {
        if (!$createdAt instanceof \DateTimeImmutable || !$arrivalAt instanceof \DateTimeImmutable) {
            return null;
        }

        $seconds = $arrivalAt->getTimestamp() - $createdAt->getTimestamp();
        if ($seconds < 0) {
            return null;
        }

        return (int) round($seconds / 60);
    }

    /**
     * Upper bound of the half-open band (n-5, n] in minutes, capped at {@see MAX_MINUTES}.
     *
     * @psalm-pure
     */
    public static function highlightMinutes(?int $recordedMinutes): ?int
    {
        if (null === $recordedMinutes || $recordedMinutes <= 0) {
            return null;
        }

        if ($recordedMinutes > self::MAX_MINUTES) {
            return self::MAX_MINUTES;
        }

        $steps = intdiv($recordedMinutes + self::INTERVAL_MINUTES - 1, self::INTERVAL_MINUTES);
        $band = $steps * self::INTERVAL_MINUTES;

        return max(self::INTERVAL_MINUTES, $band);
    }

    /**
     * @psalm-pure
     */
    public static function exceedsMaximum(?int $recordedMinutes): bool
    {
        return null !== $recordedMinutes && $recordedMinutes > self::MAX_MINUTES;
    }

    /**
     * @param array{type: string, features: list<array<string, mixed>>, properties?: array<string, mixed>} $catalog
     *
     * @return array{type: string, features: list<array<string, mixed>>}|null
     *
     * @psalm-pure
     */
    public static function collectionForRecordedMinutes(array $catalog, ?int $recordedMinutes): ?array
    {
        $highlightMinutes = self::highlightMinutes($recordedMinutes);
        if (null === $highlightMinutes) {
            return null;
        }

        $highlightSeconds = $highlightMinutes * 60;
        foreach ($catalog['features'] as $feature) {
            $properties = $feature['properties'] ?? null;
            $value = \is_array($properties) ? ($properties['value'] ?? null) : null;
            if ((int) $value === $highlightSeconds) {
                return [
                    'type' => 'FeatureCollection',
                    'features' => [$feature],
                ];
            }
        }

        return null;
    }
}
