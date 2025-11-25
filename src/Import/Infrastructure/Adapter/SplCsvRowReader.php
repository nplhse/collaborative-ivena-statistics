<?php

namespace App\Import\Infrastructure\Adapter;

use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Infrastructure\Charset\EncodingDetector;

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

    private \SplFileObject $file;

    public function __construct(
        \SplFileObject $file,
        private readonly EncodingDetector $detector,
        private readonly SplCsvStreamFactory $streamFactory,
        private readonly string $encodingHint = 'auto',
        string $delimiter = ';',
        string $enclosure = '"',
        string $escape = '\\',
    ) {
        $path = $file->getRealPath();
        if (false === $path) {
            throw new \RuntimeException('Cannot resolve CSV path.');
        }

        $sourceEncoding = $this->detector->detectFromPath($path, $this->encodingHint);

        $this->file = $this->streamFactory->openUtf8($path, $sourceEncoding, $delimiter, $enclosure, $escape);

        $this->rawHeaderRow = $this->readUtf8Row();
        if (null === $this->rawHeaderRow) {
            throw new \RuntimeException('CSV appears to be empty or header row could not be read.');
        }

        $normalized = \array_map([$this, 'normalizeHeader'], $this->rawHeaderRow);
        $this->headerRow = $this->makeUniqueHeaders($normalized);
    }

    #[\Override]
    public function header(): ?array
    {
        return $this->headerRow;
    }

    #[\Override]
    public function rows(): iterable
    {
        while (!$this->file->eof()) {
            /** @var array<int,string|null>|false $row */
            $row = $this->file->fgetcsv();
            if (false === $row || $row === [null]) {
                continue;
            }

            $utf8 = [];
            foreach ($row as $i => $value) {
                $cell = $value ?? '';
                if (0 === $i) {
                    $cell = $this->stripBom($cell);
                }
                $cell = $this->nfc($cell);
                $utf8[] = \trim($cell);
            }

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
            yield \array_combine($this->headerRow, $row);
        }
    }

    /** @return array<int,string>|null */
    private function readUtf8Row(): ?array
    {
        $row = $this->file->fgetcsv();
        if (false === $row || $row === [null]) {
            return null;
        }

        $out = [];
        foreach ($row as $i => $value) {
            $cell = $value ?? '';
            if (0 === $i) {
                $cell = $this->stripBom($cell);
            }
            $cell = $this->nfc($cell);
            $out[] = \trim($cell);
        }

        return $out;
    }

    private function nfc(string $value): string
    {
        if (\function_exists('normalizer_normalize')) {
            $formC = \defined('\Normalizer::FORM_C') ? \Normalizer::FORM_C : 4;
            $n = \normalizer_normalize($value, $formC);

            return false !== $n ? $n : $value;
        }

        if (\class_exists(\Normalizer::class)) {
            /** @psalm-suppress InternalMethod */
            $n = \Normalizer::normalize($value, \Normalizer::FORM_C);

            return false !== $n ? $n : $value;
        }

        return $value;
    }

    private function stripBom(string $value): string
    {
        return \str_starts_with($value, "\xEF\xBB\xBF") ? \substr($value, 3) : $value;
    }

    private function normalizeHeader(string $header): string
    {
        $header = $this->nfc(\trim($header));

        $map = [
            'ä' => 'ae', 'Ä' => 'ae', 'ö' => 'oe', 'Ö' => 'oe', 'ü' => 'ue', 'Ü' => 'ue', 'ß' => 'ss',
        ];
        $header = \strtr($header, $map);
        $header = \preg_replace('/[^A-Za-z0-9]+/', ' ', $header) ?? '';
        $header = \trim(\preg_replace('/\s+/', ' ', $header) ?? '');

        return '' === $header ? '' : \implode('_', \explode(' ', \strtolower($header)));
    }

    /**
     * @param array<int, string> $headers
     *
     * @return list<string>
     */
    private function makeUniqueHeaders(array $headers): array
    {
        $result = [];
        $seen = [];
        foreach ($headers as $idx => $h) {
            $name = '' !== $h ? $h : 'col_'.($idx + 1);
            if (!isset($seen[$name])) {
                $seen[$name] = 1;
                $result[] = $name;
            } else {
                ++$seen[$name];
                $result[] = $name.'_'.$seen[$name]; // ab _2
            }
        }

        return $result;
    }
}
