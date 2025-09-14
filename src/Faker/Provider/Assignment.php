<?php

namespace App\Faker\Provider;

use Faker\Provider\Base;

/** @psalm-suppress PropertyNotSetInConstructor */
final class Assignment extends Base
{
    /** @var list<string> */
    protected static array $assignments = [
        'Arzt/Arzt',
        'Einweisung',
        'Notzuweisung',
        'Patient',
        'RD',
        'ZLST',
    ];

    public function assignment(): string
    {
        return static::randomElement(static::$assignments);
    }
}
