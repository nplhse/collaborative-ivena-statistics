<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Mapping;

final class DispatchAreaNameNormalizer
{
    public function normalize(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = preg_replace([
            '/^Leitstelle\s+/u',
            '/^Kreis\s+/u',
            '/\s*\(.+$/u',
            '/\s*-\s*Kreis$/u',
            '/\s*Kreis$/u',
        ], ['', ''], $value);

        if (null === $value) {
            return null;
        }

        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        $knownTypos = [
            'Groá-Gerau' => 'Groß-Gerau',
        ];
        if (isset($knownTypos[$value])) {
            $value = $knownTypos[$value];
        }

        return $value;
    }
}
