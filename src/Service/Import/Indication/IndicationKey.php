<?php

namespace App\Service\Import\Indication;

final class IndicationKey
{
    public static function normalizeCode(?string $raw): string
    {
        if (null === $raw) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ('' === $digits) {
            return '';
        }

        return str_pad(mb_substr($digits, 0, 3), 3, '0', STR_PAD_LEFT);
    }

    public static function normalizeText(?string $raw): string
    {
        if (null === $raw) {
            return '';
        }

        $s = trim($raw);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return mb_strtolower($s, 'UTF-8');
    }

    public static function hashFrom(?string $code, ?string $text): string
    {
        return hash('md5', self::normalizeCode($code).'_'.self::normalizeText($text));
    }
}
