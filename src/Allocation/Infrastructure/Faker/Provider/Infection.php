<?php

namespace App\Allocation\Infrastructure\Faker\Provider;

use Faker\Provider\Base;

/** @psalm-suppress PropertyNotSetInConstructor */
final class Infection extends Base
{
    /** @var list<string> */
    protected static array $infections = [
        '3MRGN',
        '4MRGN/CRE',
        'Influenza',
        'MRSA',
        'Noro',
        'Sonstiges',
        'TBC',
        'V.a. COVID',
    ];

    public function infection(): string
    {
        return static::randomElement(static::$infections);
    }
}
