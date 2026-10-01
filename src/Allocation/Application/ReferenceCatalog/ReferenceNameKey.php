<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

final class ReferenceNameKey
{
    public static function normalize(string $name): string
    {
        $trimmed = mb_strtolower(trim($name), 'UTF-8');
        $normalized = preg_replace('/\s+/', ' ', $trimmed);

        return $normalized ?? $trimmed;
    }
}
