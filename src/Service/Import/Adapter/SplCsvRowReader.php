<?php

namespace App\Service\Import\Adapter;

use App\Service\Import\Contracts\RowReaderInterface;

/**
 * SplCsvRowReader.
 *
 * A streaming CSV reader built on top of PHP's SplFileObject with the following features:
 *
 *  - Streams rows without loading the entire file into memory
 *  - Converts input encoding (e.g. ISO-8859-1) to UTF-8
 *  - Automatically normalizes header names into snake_case, replacing German umlauts and ß
 *    with ASCII equivalents (ä→ae, ö→oe, ü→ue, ß→ss)
 *  - Provides both raw rows (numeric arrays) and associative rows with normalized headers
 *  - Skips empty lines and trims values
 *
 * Example:
 *   CSV Header: "Ärztlich Begleitet"
 *   Normalized: "aerztlich_begleitet"
 *
 * Use rows() to iterate over raw numeric rows,
 * or rowsAssoc() to iterate over associative rows keyed by normalized headers.
 *
 * This allows robust and predictable mapping from CSV files to DTOs and Entities,
 * independent of original header formatting or character set.
 */
final class SplCsvRowReader implements RowReaderInterface
{
    /** @var array<int,string>|null */
    private ?array $headerRow = null;

    /** @var array<int,string>|null */
    private ?array $rawHeaderRow = null;

    public function __construct(
        private readonly \SplFileObject $file,
        private string $delimiter = ';',
        private string $enclosure = '"',
        private string $escape = '\\',
        private bool $hasHeader = true,
        private string $inputEncoding = 'ISO-8859-1',
    ) {
        $this->file->setFlags(
            \SplFileObject::READ_CSV
            | \SplFileObject::SKIP_EMPTY
            | \SplFileObject::DROP_NEW_LINE
        );
        $this->file->setCsvControl($this->delimiter, $this->enclosure, $this->escape);

        if ($this->hasHeader) {
            $this->rawHeaderRow = $this->readUtf8Row();
            if (null !== $this->rawHeaderRow) {
                $this->headerRow = array_map([$this, 'normalizeHeader'], $this->rawHeaderRow);
            }
        }
    }

    /**
     * Normalized header (snake_case).
     *
     * @return array<int,string>|null
     */
    public function header(): ?array
    {
        return $this->headerRow;
    }

    /**
     * Original header (UTF-8, unnormalized).
     *
     * @return array<int,string>|null
     */
    public function rawHeader(): ?array
    {
        return $this->rawHeaderRow;
    }

    /**
     * Iterate raw numeric rows (UTF-8, trimmed).
     *
     * @return iterable<array<int,string>>
     */
    public function rows(): iterable
    {
        foreach ($this->file as $row) {
            if (false === $row || $row === [null]) {
                continue;
            }

            yield array_map(fn ($v) => $this->toUtf8($v), $row);
        }
    }

    /**
     * @return iterable<array<string,string>>
     */
    public function rowsAssoc(): iterable
    {
        if (!$this->headerRow) {
            throw new \RuntimeException('rowsAssoc() requires hasHeader=true and a valid header row.');
        }
        foreach ($this->rows() as $row) {
            // array_combine absichern
            $values = array_slice($row, 0, count($this->headerRow));
            $values += array_fill(0, count($this->headerRow) - count($values), '');
            yield array_combine($this->headerRow, $values);
        }
    }

    /**
     * @return array<int,string>|null
     */
    private function readUtf8Row(): ?array
    {
        $row = $this->file->fgetcsv();
        if (false === $row || $row === [null]) {
            return null;
        }

        return array_map(fn ($v) => $this->toUtf8($v), $row);
    }

    private function toUtf8(?string $v): string
    {
        $v = $v ?? '';
        if ('UTF-8' !== $this->inputEncoding) {
            $v = mb_convert_encoding($v, 'UTF-8', $this->inputEncoding);
        }

        return trim($v);
    }

    /**
     * Normalizes raw CSV header names into a predictable, developer-friendly format.
     *
     * Effects:
     *  - Converts to UTF-8 and trims whitespace
     *  - Replaces German umlauts and ß with ASCII equivalents (ä → ae, ö → oe, ü → ue, ß → ss)
     *  - Removes non-alphanumeric characters (replaced by spaces)
     *  - Converts to lowercase
     *  - Joins words with underscores (snake_case)
     *
     * Example:
     *   "Krankenhaus Kurzname"   → "krankenhaus_kurzname"
     *   "KHS-Versorgungsgebiet"  → "khs_versorgungsgebiet"
     *   "Ärztlich Begleitet"     → "aerztlich_begleitet"
     *
     * This ensures headers can be used as stable array keys in mappers and DTOs,
     * independent of original formatting, casing, or special characters.
     */
    private function normalizeHeader(string $header): string
    {
        $header = trim($header);

        $map = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
            'ß' => 'ss',
        ];
        $header = strtr($header, $map);

        // Nicht-Alnum zu Leerzeichen
        $header = preg_replace('/[^A-Za-z0-9]+/', ' ', $header) ?? '';

        // collapse spaces
        $header = trim(preg_replace('/\s+/', ' ', $header) ?? '');

        if ('' === $header) {
            return '';
        }

        $parts = explode(' ', strtolower($header));

        return implode('_', $parts);
    }
}
