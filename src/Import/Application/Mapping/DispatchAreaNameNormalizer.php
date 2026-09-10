<?php

declare(strict_types=1);

namespace App\Import\Application\Mapping;

final class DispatchAreaNameNormalizer
{
    /** @var list<string> longest first */
    private const array PREFIXES = [
        'Kommunale Regionalleitstelle',
        'Integrierte Leitstelle',
        'Zentrale Leitstelle',
        'Regionalleitstelle',
        'Leitstelle',
        'Landkreis',
        'Kreis',
    ];

    public function normalize(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = str_replace("\u{00A0}", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);
        $value = preg_replace('/^_+/u', '', $value) ?? $value;
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        $value = preg_replace('/\s*\(.+$/u', '', $value) ?? $value;
        $value = trim($value);

        foreach (self::PREFIXES as $prefix) {
            $pattern = '/^'.preg_quote($prefix, '/').'\s+/ui';
            $stripped = preg_replace($pattern, '', $value);
            if (null !== $stripped && $stripped !== $value) {
                $value = trim($stripped);
            }
        }

        $value = preg_replace('/\s*-\s*Kreis$/u', '', $value) ?? $value;
        $value = preg_replace('/\s*Kreis$/u', '', $value) ?? $value;
        $value = trim($value);

        $value = preg_replace('/\s+Führungsstab$/ui', '', $value) ?? $value;
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        $knownTypos = [
            'Groá-Gerau' => 'Groß-Gerau',
        ];

        return $knownTypos[$value] ?? $value;
    }
}
