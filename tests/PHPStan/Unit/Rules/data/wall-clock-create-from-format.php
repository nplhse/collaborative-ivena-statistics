<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Unit\Rules\Data;

use DateTimeImmutable;
use Symfony\Component\Clock\DatePoint;

function wallClockCreateFromFormat(string $value, string $variableFormat, bool $flag): void
{
    \DateTimeImmutable::createFromFormat('Y-m-d', $value);
    \DateTime::createFromFormat('d.m.Y H:i', $value);
    DateTimeImmutable::createFromFormat('Y-m-d', $value, new \DateTimeZone('Europe/Berlin'));
    DatePoint::createFromFormat('Y-m-d', $value);
    date_create_from_format('Y-m-d', $value);
    date_create_immutable_from_format('Y-m-d', $value);
    \DateTimeImmutable::createFromFormat(datetime: $value, format: 'Y-m-d');
    \DateTimeImmutable::createFromFormat($flag ? '!Y-m-d' : 'Y-m-d H:i:s', $value);

    foreach (['!Y-m-d H:i:s', 'd.m.Y'] as $format) {
        \DateTimeImmutable::createFromFormat($format, $value);
    }

    // Not reported: formats that reset missing fields, dynamic formats and unrelated methods.
    \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    \DateTimeImmutable::createFromFormat('Y-m-d|', $value);
    date_create_immutable_from_format('!d.m.Y H:i', $value);
    \DateTimeImmutable::createFromFormat($variableFormat, $value);
    \DateTimeImmutable::createFromFormat('!'.$variableFormat, $value);
    \DateTimeImmutable::createFromInterface(new \DateTime('2026-05-01'));
    UnrelatedFactory::createFromFormat('Y-m-d', $value);
}

final class UnrelatedFactory
{
    public static function createFromFormat(string $format, string $value): string
    {
        return $format.$value;
    }
}
