<?php

declare(strict_types=1);

namespace App\Allocation\Application\Allocations;

/**
 * Maps public inclusive Y-m-d created-at filters (and legacy exclusive datetimes) to SQL bounds.
 */
final readonly class AllocationCreatedAtRangeParser
{
    public const string DATE_QUERY_FORMAT = 'Y-m-d';

    public function parse(
        ?string $createdFrom,
        ?string $createdUntil,
        ?string $createdToExclusive = null,
    ): AllocationCreatedAtRange {
        $from = $this->parseDateTime($createdFrom);
        $until = $this->parseDateTime($createdUntil);
        $legacyExclusive = $this->parseDateTime($createdToExclusive);

        $toExclusive = null;
        if ($until instanceof \DateTimeImmutable) {
            $toExclusive = $until->setTime(0, 0, 0)->modify('+1 day');
        } elseif ($legacyExclusive instanceof \DateTimeImmutable) {
            $toExclusive = $legacyExclusive;
        }

        return new AllocationCreatedAtRange(
            from: $from,
            toExclusive: $toExclusive,
            fromDate: $from?->format(self::DATE_QUERY_FORMAT),
            untilDate: $until instanceof \DateTimeImmutable
                ? $until->format(self::DATE_QUERY_FORMAT)
                : ($legacyExclusive instanceof \DateTimeImmutable
                    ? self::inclusiveDateFromExclusiveEnd($legacyExclusive)
                    : null),
        );
    }

    public static function inclusiveDateFromExclusiveEnd(\DateTimeImmutable $toExclusive): string
    {
        if ('00:00:00' === $toExclusive->format('H:i:s')) {
            return $toExclusive->modify('-1 day')->format(self::DATE_QUERY_FORMAT);
        }

        return $toExclusive->format(self::DATE_QUERY_FORMAT);
    }

    private function parseDateTime(?string $value): ?\DateTimeImmutable
    {
        $value = $this->blankToNull($value);
        if (null === $value) {
            return null;
        }

        foreach (['Y-m-d\TH:i:s', 'Y-m-d H:i:s', '!Y-m-d'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed instanceof \DateTimeImmutable) {
                return $parsed;
            }
        }

        return null;
    }

    private function blankToNull(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
