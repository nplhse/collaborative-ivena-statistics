<?php

namespace App\Service\Import\Mapping;

trait AllocationRowNormalizationTrait
{
    /**
     * Returns a trimmed string or null if the key is missing or the value is empty.
     */
    protected static function getStringOrNull(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row)) {
            return null;
        }
        $value = trim((string) $row[$key]);

        return '' === $value ? null : $value;
    }

    /**
     * Combines a date and a time into "d.m.Y H:i". Returns null if either part is
     * missing.
     */
    protected static function combineDateAndTime(?string $date, ?string $time): ?string
    {
        if (null === $date || null === $time) {
            return null;
        }

        $time = substr($time, 0, 5);

        return sprintf('%s %s', $date, $time);
    }

    /**
     * Normalizes a datetime string to "d.m.Y H:i" if parsable, otherwise returns the
     * input trimmed. Accepts "d.m.Y H:i" and "d.m.Y H:i:s".
     */
    protected static function normalizeDateTimeString(string $value): string
    {
        $v = trim($value);
        // Try H:i
        $dt = \DateTimeImmutable::createFromFormat('d.m.Y H:i', $v);
        if ($dt instanceof \DateTimeImmutable) {
            return $dt->format('d.m.Y H:i');
        }
        // Try H:i:s
        $dt = \DateTimeImmutable::createFromFormat('d.m.Y H:i:s', $v);
        if ($dt instanceof \DateTimeImmutable) {
            return $dt->format('d.m.Y H:i');
        }

        // As-is (validator will catch wrong formats)
        return $v;
    }

    /**
     * Chooses the correct createdAt value:
     * - If both combined (date+time) and "erstellungsdatum" exist and differ
     *   (normalized), prefer "erstellungsdatum".
     * - If only one exists, return that.
     * - If both missing, return null.
     */
    protected static function chooseCreatedAt(?string $combined, ?string $erstellungsdatum): ?string
    {
        if (null === $erstellungsdatum && null === $combined) {
            return null;
        }
        if (null !== $erstellungsdatum && null === $combined) {
            return self::normalizeDateTimeString($erstellungsdatum);
        }
        if (null === $erstellungsdatum && null !== $combined) {
            return self::normalizeDateTimeString($combined);
        }

        // Both exist → compare normalized "d.m.Y H:i"
        $c = self::normalizeDateTimeString($combined);
        $e = self::normalizeDateTimeString($erstellungsdatum);

        return $c === $e ? $c : $e;
    }

    /**
     * Normalizes an age value as integer, or null if not numeric. Range checking (1..99)
     * is handled by the DTO validator, not here.
     */
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
     *   - textual yes/no (ja/yes/true/1, nein/no/false/0)
     *
     * Unknown or empty values return null so the DTO constraints can decide.
     */
    protected static function normalizeBoolean(?string $value): ?bool
    {
        if (null === $value) {
            return null;
        }

        $clean = trim((string) $value);
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

        $truthy = ['ja', 'j', '1', 'wahr', 'x'];
        $falsy = ['nein', 'n', '0', 'falsch'];

        if (in_array($lower, $truthy, true)) {
            return true;
        }
        if (in_array($lower, $falsy, true)) {
            return false;
        }

        return null;
    }

    /**
     * Normalizes gender as per new rule:
     *   - Only "M" or "W" are kept.
     *   - Any other value (including "D") becomes "X".
     *
     * Empty/unknown values also become "X" to ensure downstream enum mapping.
     */
    protected static function normalizeGender(?string $value): string
    {
        if (null === $value || '' === trim($value)) {
            return 'X';
        }

        $code = strtoupper(trim($value));
        if ('M' === $code || 'W' === $code) {
            return $code;
        }

        return 'X';
    }

    /**
     * Normalizes transport type to the only two allowed canonical outputs:
     *   - "GROUND"
     *   - "AIR"
     *
     * Mappings:
     *   - "NAW", "ITW", "MZF", "RTW" → "Ground"
     *   - "Boden" → "Ground"
     *   - "Luft"  → "Air"
     *
     * Any other input results in null (so the DTO Choice can reject or accept null).
     */
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
            return 'Ground';
        }

        if ('Luft' === $normalized) {
            return 'Air';
        }

        // Known ground-transport aliases
        $groundAliases = ['Naw', 'Itw', 'Mzf', 'Rtw'];
        if (in_array($normalized, $groundAliases, true)) {
            return 'Ground';
        }

        return null;
    }
}
