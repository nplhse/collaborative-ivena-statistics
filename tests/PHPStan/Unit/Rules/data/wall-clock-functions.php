<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Unit\Rules\Data;

function wallClockFunctions(int $timestamp, string $variable): void
{
    time();
    date('Y');
    \date('Ymd_His');
    gmdate('Y-m-d');
    strtotime('+1 day');
    mktime(0, 0, 0, 1, 1);
    date_create();
    date_create_immutable('today');
    getdate();

    // Not reported: an explicit timestamp, absolute values and duration measurement.
    date('Y', $timestamp);
    date(format: 'Y', timestamp: $timestamp);
    strtotime('+1 day', $timestamp);
    strtotime($variable);
    mktime(0, 0, 0, 1, 1, 2026);
    date_create_immutable('2026-05-01');
    date_create_immutable($variable);
    microtime(true);
    hrtime(true);
    strlen($variable);
}
