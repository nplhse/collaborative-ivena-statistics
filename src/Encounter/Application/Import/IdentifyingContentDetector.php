<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

/**
 * Content checks for identifying data in a canonical exchange file (ADR 026). They reduce the risk
 * that direct identifiers reach the server; they cannot find every identifier.
 *
 * - header columns that name identifying source data (names, birth dates, case numbers, addresses,
 *   free text), which marks a raw HIS export even behind a copied manifest
 * - date-like values in fields that are not timestamps (a date of birth in the age column)
 * - name-like values: "Surname, Given name" or a title before a capitalised word
 * - long free text: any cell longer than a typed value of the format can be
 *
 * Findings never carry the cell value.
 */
final class IdentifyingContentDetector
{
    /** Longest typed value of the format: an HMAC (64 hex characters). Code sets are checked separately. */
    public const int MAX_CELL_LENGTH = 64;

    /** All nine isolation codes joined by commas stay below this. */
    public const int MAX_CODE_SET_LENGTH = 128;

    /** Lowercase fragments of column names that identify persons or carry free text. */
    private const array IDENTIFYING_HEADER_FRAGMENTS = [
        'name', 'geburt', 'birth', 'fallnummer', 'fall-id', 'fall_id', 'case_number', 'casenumber', 'patient',
        'adresse', 'address', 'strasse', 'straße', 'street', 'telefon', 'phone', 'versicher', 'insurance',
        'bemerkung', 'remark', 'comment', 'kommentar', 'allergie', 'grund', 'arzt', 'pflege', 'einschätzer',
    ];

    private const string DATE_PATTERN = '/\b\d{1,2}\.\d{1,2}\.\d{2,4}\b|\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/';

    private const string NAME_PATTERN = '/\p{Lu}\p{Ll}+\s*,\s*\p{Lu}\p{Ll}+|\b(?:Dr|Prof|Herr|Frau|Mr|Mrs|Ms)\.?\s+\p{Lu}/u';

    /**
     * Whether a header column that is not part of the schema names identifying source data.
     */
    public function isIdentifyingColumn(string $column): bool
    {
        $normalised = mb_strtolower($column);

        return array_any(self::IDENTIFYING_HEADER_FRAGMENTS, static fn (string $fragment): bool => str_contains($normalised, $fragment));
    }

    /**
     * @param bool $timestamp the column holds timestamps, where date-like values are expected
     * @param bool $codeSet   the column holds a comma-separated code list
     */
    public function isIdentifyingCell(string $value, bool $timestamp, bool $codeSet): bool
    {
        if ('' === $value) {
            return false;
        }

        if (mb_strlen($value) > ($codeSet ? self::MAX_CODE_SET_LENGTH : self::MAX_CELL_LENGTH)) {
            return true;
        }

        if (1 === preg_match(self::NAME_PATTERN, $value)) {
            return true;
        }

        return !$timestamp && 1 === preg_match(self::DATE_PATTERN, $value);
    }
}
