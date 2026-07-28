<?php

declare(strict_types=1);

namespace App\Shared\Application\Export;

/**
 * Neutralizes CSV formula injection for spreadsheet clients (Excel, LibreOffice).
 *
 * Only string cells should be passed here; numeric types stay unescaped so values
 * like -42 remain plain numbers rather than formula-like text.
 */
final class CsvFormulaEscaper
{
    private const array DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function escape(string $value): string
    {
        if ('' === $value) {
            return '';
        }

        if (\in_array($value[0], self::DANGEROUS_PREFIXES, true)) {
            return "'".$value;
        }

        return $value;
    }
}
