<?php

declare(strict_types=1);

namespace App\Import\Application\Mapping;

/**
 * Repairs CP850 (DOS/OEM) bytes that were stored as ISO-8859-1 / Unicode C1 controls.
 *
 * Example: Häuslicher → H + U+0084 + uslicher, Öffentlicher → U+0099 + ffentlicher.
 */
final class Cp850MojibakeRepair
{
    public static function repair(string $value): string
    {
        if (!self::hasC1Controls($value)) {
            return $value;
        }

        $bytes = '';
        $length = mb_strlen($value, 'UTF-8');
        for ($i = 0; $i < $length; ++$i) {
            $codepoint = mb_ord(mb_substr($value, $i, 1, 'UTF-8'), 'UTF-8');
            if (false === $codepoint || $codepoint > 0xFF) {
                return $value;
            }

            $bytes .= \chr($codepoint);
        }

        $repaired = mb_convert_encoding($bytes, 'UTF-8', 'CP850');
        if (!\is_string($repaired) || !mb_check_encoding($repaired, 'UTF-8')) {
            return $value;
        }

        return $repaired;
    }

    public static function hasC1Controls(string $value): bool
    {
        return 1 === preg_match('/[\x{0080}-\x{009F}]/u', $value);
    }
}
