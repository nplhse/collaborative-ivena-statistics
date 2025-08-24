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

    /**
     * @psalm-suppress UnusedProperty
     *
     * @var array<int,string>|null
     */
    private ?array $rawHeaderRow = null;

    public function __construct(
        private readonly \SplFileObject $file,
        private readonly string $inputEncoding = 'ISO-8859-1',
        string $delimiter = ';',
        string $enclosure = "\0",
        string $escape = '\\',
    ) {
        $this->file->setFlags(
            \SplFileObject::READ_CSV
            | \SplFileObject::SKIP_EMPTY
            | \SplFileObject::DROP_NEW_LINE
        );

        $this->file->setCsvControl($delimiter, $enclosure, $escape);

        $this->rawHeaderRow = $this->readUtf8Row();
        if (null === $this->rawHeaderRow) {
            throw new \RuntimeException('CSV appears to be empty or header row could not be read.');
        }

        $this->headerRow = array_map([$this, 'normalizeHeader'], $this->rawHeaderRow);
    }

    /**
     * Normalized header (snake_case).
     *
     * @return array<int,string>|null
     */
    #[\Override]
    public function header(): ?array
    {
        return $this->headerRow;
    }

    /**
     * @return iterable<array<int,string>>
     */
    #[\Override]
    public function rows(): iterable
    {
        while (!$this->file->eof()) {
            /** @var array<int,string|null>|false $row */
            $row = $this->file->fgetcsv();

            if (false === $row || $row === [null]) {
                continue;
            }

            $utf8 = \array_map(fn (?string $value): string => $this->toUtf8($value), $row);
            $allEmpty = true;

            foreach ($utf8 as $cell) {
                if ('' !== $cell) {
                    $allEmpty = false;
                    break;
                }
            }

            if ($allEmpty) {
                continue;
            }

            yield $utf8;
        }
    }

    /**
     * @return iterable<array<string,string>>
     */
    #[\Override]
    public function rowsAssoc(): iterable
    {
        if (null === $this->headerRow) {
            throw new \RuntimeException('CSV header is missing or invalid.');
        }

        $len = \count($this->headerRow);

        foreach ($this->rows() as $row) {
            $cnt = \count($row);

            if ($cnt > $len) {
                $row = \array_slice($row, 0, $len);
            } elseif ($cnt < $len) {
                $row = \array_pad($row, $len, '');
            }

            $assoc = \array_combine($this->headerRow, $row);
            $assoc = \array_map(static fn (string $value): string => $value, $assoc);

            yield $assoc;
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

        return \array_map(fn (?string $value): string => $this->toUtf8($value), $row);
    }

    private function toUtf8(?string $value): string
    {
        $value = $value ?? '';

        if ('UTF-8' !== $this->inputEncoding) {
            $converted = \mb_convert_encoding($value, 'UTF-8', $this->inputEncoding);
            $value = (string) $converted;
        }

        return trim($value);
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
        $header = preg_replace('/[^A-Za-z0-9]+/', ' ', $header) ?? '';
        $header = trim(preg_replace('/\s+/', ' ', $header) ?? '');

        if ('' === $header) {
            return '';
        }

        $parts = explode(' ', strtolower($header));

        return implode('_', $parts);
    }
}
