<?php

declare(strict_types=1);

namespace App\Import\Application\Mapping;

final class DepartmentNameAlias
{
    /** @var array<string, string> normalized import value => normalized canonical department name */
    private const array ALIASES = [
        'perinatalzentrum level 1' => 'geburtshilfe',
        'perinatalzentrum level 2' => 'geburtshilfe',
        'perinataler schwerpunkt' => 'geburtshilfe',
        'geburtsklinik' => 'geburtshilfe',
    ];

    public static function canonicalKey(string $normalizedKey): string
    {
        return self::ALIASES[$normalizedKey] ?? $normalizedKey;
    }
}
