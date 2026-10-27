<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rules;

/**
 * Shared wording for the wall clock rules, so every report points to the same fix.
 */
final class WallClockMessage
{
    public const string IDENTIFIER_DATE_TIME = 'wallClock.dateTime';
    public const string IDENTIFIER_FUNCTION = 'wallClock.function';
    public const string IDENTIFIER_CREATE_FROM_FORMAT = 'wallClock.createFromFormat';

    public static function message(string $expression): string
    {
        return sprintf(
            'Reading the wall clock with %s bypasses the Symfony clock and is invisible to ClockSensitiveTrait::mockTime().',
            $expression,
        );
    }

    public static function tip(): string
    {
        return 'Inject Psr\Clock\ClockInterface and call $this->clock->now(); in entities or static code use Symfony\Component\Clock\Clock::get()->now(). See docs/03-development/testing.md (Time in code and tests).';
    }

    public static function createFromFormatTip(): string
    {
        return "Start the format with '!' (e.g. '!Y-m-d'), so fields that are not in the format are reset instead of taken from the current time. See docs/03-development/testing.md (Time in code and tests).";
    }
}
