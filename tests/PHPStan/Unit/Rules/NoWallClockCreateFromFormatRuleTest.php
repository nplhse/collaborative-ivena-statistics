<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Unit\Rules;

use App\Tests\PHPStan\Rules\NoWallClockCreateFromFormatRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoWallClockCreateFromFormatRule>
 */
final class NoWallClockCreateFromFormatRuleTest extends RuleTestCase
{
    private const string TIP = "Start the format with '!' (e.g. '!Y-m-d'), so fields that are not in the format are reset instead of taken from the current time. See docs/03-development/testing.md (Time in code and tests).";

    protected function getRule(): Rule
    {
        return new NoWallClockCreateFromFormatRule($this->createReflectionProvider());
    }

    public function testReportsFormatsWithoutReset(): void
    {
        $this->analyse([__DIR__.'/data/wall-clock-create-from-format.php'], [
            [$this->message("\\DateTimeImmutable::createFromFormat('Y-m-d')"), 12, self::TIP],
            [$this->message("\\DateTime::createFromFormat('d.m.Y H:i')"), 13, self::TIP],
            [$this->message("\\DateTimeImmutable::createFromFormat('Y-m-d')"), 14, self::TIP],
            [$this->message("\\Symfony\\Component\\Clock\\DatePoint::createFromFormat('Y-m-d')"), 15, self::TIP],
            [$this->message("date_create_from_format('Y-m-d')"), 16, self::TIP],
            [$this->message("date_create_immutable_from_format('Y-m-d')"), 17, self::TIP],
            [$this->message("\\DateTimeImmutable::createFromFormat('Y-m-d')"), 18, self::TIP],
            [$this->message("\\DateTimeImmutable::createFromFormat('Y-m-d H:i:s')"), 19, self::TIP],
            [$this->message("\\DateTimeImmutable::createFromFormat('d.m.Y')"), 22, self::TIP],
        ]);
    }

    private function message(string $expression): string
    {
        return sprintf('Reading the wall clock with %s bypasses the Symfony clock and is invisible to ClockSensitiveTrait::mockTime().', $expression);
    }
}
