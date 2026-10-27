<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Unit\Rules;

use App\Tests\PHPStan\Rules\NoWallClockFunctionCallRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoWallClockFunctionCallRule>
 */
final class NoWallClockFunctionCallRuleTest extends RuleTestCase
{
    private const string TIP = 'Inject Psr\Clock\ClockInterface and call $this->clock->now(); in entities or static code use Symfony\Component\Clock\Clock::get()->now(). See docs/03-development/testing.md (Time in code and tests).';

    protected function getRule(): Rule
    {
        return new NoWallClockFunctionCallRule($this->createReflectionProvider());
    }

    public function testReportsWallClockReads(): void
    {
        $this->analyse([__DIR__.'/data/wall-clock-functions.php'], [
            [$this->message('time()'), 9, self::TIP],
            [$this->message('date() without $timestamp'), 10, self::TIP],
            [$this->message('date() without $timestamp'), 11, self::TIP],
            [$this->message('gmdate() without $timestamp'), 12, self::TIP],
            [$this->message("strtotime('+1 day')"), 13, self::TIP],
            [$this->message('mktime() without $year'), 14, self::TIP],
            [$this->message('date_create()'), 15, self::TIP],
            [$this->message("date_create_immutable('today')"), 16, self::TIP],
            [$this->message('getdate() without $timestamp'), 17, self::TIP],
        ]);
    }

    private function message(string $expression): string
    {
        return sprintf('Reading the wall clock with %s bypasses the Symfony clock and is invisible to ClockSensitiveTrait::mockTime().', $expression);
    }
}
