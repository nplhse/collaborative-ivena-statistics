<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Unit\Rules;

use App\Tests\PHPStan\Rules\NoWallClockInstantiationRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoWallClockInstantiationRule>
 */
final class NoWallClockInstantiationRuleTest extends RuleTestCase
{
    private const string TIP = 'Inject Psr\Clock\ClockInterface and call $this->clock->now(); in entities or static code use Symfony\Component\Clock\Clock::get()->now(). See docs/03-development/testing.md (Time in code and tests).';

    protected function getRule(): Rule
    {
        return new NoWallClockInstantiationRule();
    }

    public function testReportsWallClockReads(): void
    {
        $this->analyse([__DIR__.'/data/wall-clock-instantiation.php'], [
            [$this->message('new \\DateTimeImmutable()'), 13, self::TIP],
            [$this->message("new \\DateTimeImmutable('now')"), 14, self::TIP],
            [$this->message("new \\DateTimeImmutable('today')"), 15, self::TIP],
            [$this->message("new \\DateTimeImmutable('midnight')"), 16, self::TIP],
            [$this->message("new \\DateTimeImmutable('first day of this month')"), 17, self::TIP],
            [$this->message("new \\DateTimeImmutable('-30 days')"), 18, self::TIP],
            [$this->message("new \\DateTimeImmutable('+1 year')"), 19, self::TIP],
            [$this->message("new \\DateTimeImmutable('10:00')"), 20, self::TIP],
            [$this->message('new \\DateTime()'), 21, self::TIP],
            [$this->message("new \\DateTimeImmutable('yesterday')"), 22, self::TIP],
            [$this->message("new \\DateTimeImmutable('now')"), 23, self::TIP],
            [$this->message("new \\DateTimeImmutable('')"), 24, self::TIP],
        ]);
    }

    private function message(string $expression): string
    {
        return sprintf('Reading the wall clock with %s bypasses the Symfony clock and is invisible to ClockSensitiveTrait::mockTime().', $expression);
    }
}
