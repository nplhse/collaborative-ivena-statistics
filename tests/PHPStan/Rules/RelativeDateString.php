<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rules;

/**
 * Decides whether a date string passed to the DateTime constructor depends on the current time.
 */
final class RelativeDateString
{
    /**
     * A string is absolute when it is a Unix timestamp ("@1700000000") or names a calendar date
     * (year, month and day). Everything else ("now", "today", "10:00", "-30 days") reads the wall clock.
     * Strings PHP cannot parse are not reported, because they fail at runtime anyway.
     */
    public static function isRelative(string $value): bool
    {
        $value = trim($value);

        // An empty string means "now", like a missing argument.
        if ('' === $value) {
            return true;
        }

        if (str_starts_with($value, '@')) {
            return false;
        }

        $parsed = date_parse($value);

        if ($parsed['error_count'] > 0) {
            return false;
        }

        return false === $parsed['year'] || false === $parsed['month'] || false === $parsed['day'];
    }
}
