<?php

namespace App\Import\Infrastructure\Mapping;

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

    /**
     * @param array<string, scalar|null> $row
     */
    protected static function getIntOrNull(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            return null;
        }

        $value = $row[$key];

        if (null === $value || '' === trim((string) $value)) {
            return null;
        }

        if (false !== filter_var($value, FILTER_VALIDATE_INT)) {
            return (int) $value;
        }

        return null;
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

    /**
     * Extracts the last digit from a six-digit integer string representing the
     * PZC and returns it as an int if it is 1, 2 or 3. Otherwise, returns null.
     *
     * Examples:
     *  '123451' → 1
     *  '000002' → 2
     *  '999993' → 3
     *  '123450' → null   (last digit not in 1..3)
     *  '12345'  → null   (not six digits)
     *  null     → null
     */
    protected static function normalizeUrgencyFromPZC(?string $value): ?int
    {
        if (null === $value) {
            return null;
        }

        $clean = trim($value);

        // PZC must be exactly six digits (allow leading zeros)
        if (6 !== strlen($clean) || !ctype_digit($clean)) {
            return null;
        }

        $last = (int) substr($clean, -1);

        return \in_array($last, [1, 2, 3], true) ? $last : null;
    }

    protected static function normalizeCodeFromPZC(?string $value): ?int
    {
        if (null === $value) {
            return null;
        }

        $clean = trim($value);

        // PZC must be exactly six digits (allow leading zeros)
        if (6 !== strlen($clean) || !ctype_digit($clean)) {
            return null;
        }

        return (int) substr($clean, 0, 3);
    }

    protected static function normalizeIndication(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $clean = trim($value);

        return mb_substr($clean, 4);
    }

    protected static function normalizeDispatchArea(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = preg_replace([
            '/^Leitstelle\s+/u',
            '/\s*\(.+$/u',
        ], ['', ''], $value);

        if (null === $value) {
            return null;
        }

        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        return $value;
    }

    private static function normalizeAssessmentAirway(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = preg_replace('/^[A-D]-/i', '', $value) ?? '';
        $normalized = mb_strtolower(trim($value));

        return match ($normalized) {
            'frei' => 'free',
            'gefährdet', 'gefaehrdet' => 'risk',
            'intubiert' => 'intubated',
            'krit. atemweg', 'kritischer atemweg', 'krit atemweg' => 'critical',
            default => $value,
        };
    }

    private static function normalizeAssessmentBreathing(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = preg_replace('/^[A-D]-/i', '', $value) ?? '';
        $normalized = mb_strtolower(trim($value));

        return match ($normalized) {
            'spontan' => 'spontaneous',
            'resp. insuff.', 'resp insuff.', 'resp. insuff', 'resp insuff', 'respiratorische insuffizienz' => 'insufficient',
            'cpap' => 'cpap',
            'niv' => 'niv',
            'invasiv' => 'invasive',
            default => $value,
        };
    }

    private static function normalizeAssessmentCirculation(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = preg_replace('/^[A-D]-/i', '', $value) ?? '';
        $normalized = mb_strtolower(trim($value));

        return match ($normalized) {
            'stabil' => 'stable',
            'stabil unter med.', 'stabil u. med.', 'stabil unter med' => 'medication',
            'instabil' => 'unstable',
            'laufende rea', 'rea laufend' => 'cpr',
            default => $value,
        };
    }

    private static function normalizeAssessmentDisability(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = preg_replace('/^[A-D]-/i', '', $value) ?? '';
        $normalized = mb_strtolower(trim($value));

        return match ($normalized) {
            'wach' => 'awake',
            'gcs < 15', 'gcs<15' => 'gcs_below_15',
            'gcs < 9', 'gcs<9' => 'gcs_below_9',
            'sediert' => 'sedated',
            'narkotisiert' => 'anesthetized',
            default => $value,
        };
    }
}
