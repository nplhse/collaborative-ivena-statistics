<?php

declare(strict_types=1);

namespace App\LegacyMigration\Infrastructure\Matching;

final class HospitalNameNormalizer
{
    public function normalize(string $name): string
    {
        $value = mb_strtolower(trim($name));
        $value = strtr($value, [
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'á' => 'a',
            'à' => 'a',
            'ó' => 'o',
            'ò' => 'o',
            'í' => 'i',
            'ì' => 'i',
        ]);
        $value = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value);

        $patterns = [
            '/\buniversitaetsklinikum\b/u',
            '/\buniversitätsklinikum\b/u',
            '/\bklinikum\b/u',
            '/\bklinik\b/u',
            '/\bggmbh\b/u',
            '/\bgmbh\b/u',
        ];
        $value = (string) preg_replace($patterns, ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }
}
