<?php

namespace App\Faker\Provider;

use Faker\Provider\Base;

/** @psalm-suppress PropertyNotSetInConstructor */
final class Occasion extends Base
{
    /** @var list<string> */
    protected static array $occasions = [
        'Arbeitsunfall',
        'aus Arztpraxis',
        'Diagnostik',
        'Häuslicher Einsatz',
        'Intervention',
        'Öffentlicher Raum',
        'Sekundärverlegung',
        'Sonstiger Einsatz',
        'Verkehrsunfall',
    ];

    public function occasion(): string
    {
        return static::randomElement(static::$occasions);
    }
}
