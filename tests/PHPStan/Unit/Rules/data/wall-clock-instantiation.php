<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Unit\Rules\Data;

use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\DatePoint;

function wallClockInstantiation(string $variable, int $timestamp, bool $flag, \Psr\Clock\ClockInterface $clock): void
{
    new \DateTimeImmutable();
    new \DateTimeImmutable('now');
    new \DateTimeImmutable('today');
    new \DateTimeImmutable('midnight');
    new \DateTimeImmutable('first day of this month');
    new \DateTimeImmutable('-30 days');
    new \DateTimeImmutable('+1 year');
    new \DateTimeImmutable('10:00');
    new \DateTime();
    new \DateTimeImmutable('yesterday', new \DateTimeZone('Europe/Berlin'));
    new \DateTimeImmutable($flag ? 'now' : '2026-05-01');
    new \DateTimeImmutable('');

    // Not reported: absolute values, dynamic values and the clock.
    new \DateTimeImmutable('2026-05-01 00:00:00');
    new \DateTimeImmutable('2026-05-01 +1 day');
    new \DateTimeImmutable('@'.$timestamp);
    new \DateTimeImmutable($variable);
    new \DateTimeImmutable($variable.' 00:00:00');
    new DatePoint();
    new \ArrayObject();
    Clock::get()->now()->modify('-1 day');
    $clock->now();
}
