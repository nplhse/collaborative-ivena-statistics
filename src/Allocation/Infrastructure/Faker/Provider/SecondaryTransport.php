<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

use Faker\Provider\Base;

/** @psalm-suppress PropertyNotSetInConstructor */
final class SecondaryTransport extends Base
{
    /** @var list<string> */
    protected static array $secondaryTransports = [
        'Diagnostik',
        'Intensivstation',
        'Intervention',
        'OP',
        'Sekundärverlegung',
        'Sonstiger Einsatz',
        'Weaning',
        'Kapazitätsengpass',
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return self::$secondaryTransports;
    }

    public function secondaryTransport(): string
    {
        return self::randomElement(self::$secondaryTransports);
    }
}
