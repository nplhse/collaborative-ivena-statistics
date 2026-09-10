<?php

declare(strict_types=1);

namespace App\Allocation\Domain;

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
        $s = str_replace(["\u{201C}", "\u{201D}", "\u{201E}"], '"', $s);
        // Mixed-quote IVENA rows store \"OMI\" as \OMI\"" (empty-escape leftover).
        $s = preg_replace('/\\\\([^\\\\"\/]+)\\\\""/u', '"$1"', $s) ?? $s;
        $s = str_replace('\\"', '"', $s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s*\/\s*/u', '/', $s) ?? $s;

        return mb_strtolower($s, 'UTF-8');
    }

    public static function hashFrom(?string $code, ?string $text): string
    {
        return hash('md5', self::normalizeCode($code).'_'.self::normalizeText($text));
    }
}
