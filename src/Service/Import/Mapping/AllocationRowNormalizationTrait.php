<?php

namespace App\Service\Import\Mapping;

trait AllocationRowNormalizationTrait
{
    /**
     * @param array<string, scalar|null> $row
     */
    protected static function getStringOrNull(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row)) {
            return null;
        }
        $value = trim((string) $row[$key]);

        return '' === $value ? null : $value;
    }

    protected static function combineDateAndTime(?string $date, ?string $time): ?string
    {
        if (null === $date || null === $time) {
            return null;
        }

        $time = substr($time, 0, 5);

        return sprintf('%s %s', $date, $time);
    }

    protected static function normalizeAge(?string $value): ?int
    {
        if (null === $value) {
            return null;
        }

        $clean = trim($value);
        if ('' === $clean || !is_numeric($clean)) {
            return null;
        }

        return (int) $clean;
    }

    /**
     * Normalizes a boolean-like value. Supported inputs:
     *   - suffix notation like "S+", "H-", "R+", "B-", "N+"
     *   - textual yes/no (ja/j/wahr/1/x/schwanger, nein/n/falsch/0)
     *
     * Unknown or empty values return null so the DTO constraints can decide.
     */
    protected static function normalizeBoolean(?string $value): ?bool
    {
        if (null === $value) {
            return null;
        }

        $clean = trim($value);
        if ('' === $clean) {
            return null;
        }

        // Suffix notation like "S+", "H-", "R+", "B-", "N+"
        if (str_ends_with($clean, '+')) {
            return true;
        }
        if (str_ends_with($clean, '-')) {
            return false;
        }

        $lower = mb_strtolower($clean);

        $truthy = ['ja', 'j', '1', 'wahr', 'x', 'schwanger'];
        $falsy = ['nein', 'n', '0', 'falsch', 'false'];

        if (in_array($lower, $truthy, true)) {
            return true;
        }
        if (in_array($lower, $falsy, true)) {
            return false;
        }

        return null;
    }

    protected static function normalizeGender(?string $value): string
    {
        if (null === $value || '' === trim($value)) {
            return 'X';
        }

        $code = strtoupper(trim($value));
        if ('M' === $code) {
            return 'M';
        }

        if ('W' === $code) {
            return 'F';
        }

        return 'X';
    }

    protected static function normalizeTransportType(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);
        if ('' === $trimmed) {
            return null;
        }

        $normalized = ucfirst(strtolower($trimmed));

        // canonical allowed outputs
        if ('Boden' === $normalized) {
            return 'G';
        }

        if ('Luft' === $normalized) {
            return 'A';
        }

        // Known ground-transport aliases
        $groundAliases = ['Naw', 'Itw', 'Mzf', 'Rtw'];
        if (in_array($normalized, $groundAliases, true)) {
            return 'G';
        }

        return null;
    }
}
