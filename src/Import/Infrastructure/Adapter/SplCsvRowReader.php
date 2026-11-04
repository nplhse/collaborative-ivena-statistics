<?php

declare(strict_types=1);

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
 * independent of the original header formatting or character set.
 */
final class SplCsvRowReader implements RowReaderInterface
{
    /** @var array<int,string>|null */
    private ?array $headerRow = null;

    /** @var array<int,string>|null */
    private ?array $rawHeaderRow = null;

    private readonly \SplFileObject $file;
    private readonly string $delimiter;
    private readonly string $enclosure;
    private readonly string $escape;
    private readonly IvenaQuotedCsvParser $csvParser;
    private ?int $expectedColumnCount = null;

    /**
     * Physical lines read ahead for a quoted field that never closed. They are read again
     * as records of their own.
     *
     * @var list<string>
     */
    private array $pendingLines = [];

    /**
     * Upper bound for one record that spans several physical lines. IVENA closure exports
     * list up to a few hundred departments in one cell (a few KB); a quote that never
     * closes must not swallow the rest of the file.
     */
    private const int MAX_RECORD_BYTES = 65536;

    public function __construct(
        \SplFileObject $file,
        private readonly EncodingDetector $detector,
        private readonly SplCsvStreamFactory $streamFactory,
        private readonly string $encodingHint = 'auto',
        string $delimiter = ';',
        string $enclosure = '"',
        string $escape = '',
        ?IvenaQuotedCsvParser $csvParser = null,
    ) {
        $path = $file->getRealPath();
        if (false === $path) {
            throw new \RuntimeException('Cannot resolve CSV path.');
        }

        $sourceEncoding = $this->detector->detectFromPath($path, $this->encodingHint);

        $this->file = $this->streamFactory->openUtf8($path, $sourceEncoding, $delimiter, $enclosure, $escape, readCsv: false);
        $this->delimiter = $delimiter;
        $this->enclosure = $enclosure;
        $this->escape = $escape;
        $this->csvParser = $csvParser ?? new IvenaQuotedCsvParser();

        $this->rawHeaderRow = $this->readUtf8Row();
        if (null === $this->rawHeaderRow) {
            throw new \RuntimeException('CSV appears to be empty or header row could not be read.');
        }

        $normalized = \array_map($this->normalizeHeader(...), $this->rawHeaderRow);
        $this->headerRow = $this->makeUniqueHeaders($normalized);
        $this->expectedColumnCount = \count($this->headerRow);
    }

    #[\Override]
    public function header(): ?array
    {
        return $this->headerRow;
    }

    #[\Override]
    public function rows(): iterable
    {
        while (false !== ($row = $this->readCsvRow())) {
            if ($row === [null]) {
                continue;
            }

            $utf8 = [];
            foreach ($row as $i => $value) {
                $cell = $value ?? '';
                if (0 === $i) {
                    $cell = $this->stripBom($cell);
                }
                $cell = $this->nfc($this->normalizeLineBreaks($cell));
                $utf8[] = \trim($cell);
            }
            $allEmpty = array_all($utf8, fn (string $cell): bool => '' === $cell);
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
        $row = $this->readCsvRow();
        if (false === $row || $row === [null]) {
            return null;
        }

        $out = [];
        foreach ($row as $i => $value) {
            $cell = $value ?? '';
            if (0 === $i) {
                $cell = $this->stripBom($cell);
            }
            $cell = $this->nfc($this->normalizeLineBreaks($cell));
            $out[] = \trim($cell);
        }

        return $out;
    }

    /**
     * Read one CSV record. A quoted field may continue on the next physical lines; its
     * line breaks stay part of the field. A line that already has the expected column
     * count is left intact, so an unescaped inner quote does not swallow the following row.
     * A quote that does not close before the end of the file or MAX_RECORD_BYTES keeps
     * only the first line; the lines read ahead are read again as records of their own.
     *
     * @return array<int,string|null>|false
     */
    private function readCsvRow(): array|false
    {
        $first = $this->nextLine();
        if (null === $first) {
            return false;
        }

        $line = $first;
        $parsed = $this->parseRecord($line);
        $readAhead = [];
        while ($this->quotedFieldContinues($line, $parsed)) {
            $next = \strlen($line) <= self::MAX_RECORD_BYTES ? $this->nextLine() : null;
            if (null === $next) {
                $this->pendingLines = [...$readAhead, ...$this->pendingLines];

                return $this->parseRecord($first);
            }

            $readAhead[] = $next;
            $line .= $next;
            $parsed = $this->parseRecord($line);
        }

        return $parsed;
    }

    private function nextLine(): ?string
    {
        if ([] !== $this->pendingLines) {
            return \array_shift($this->pendingLines);
        }

        if ($this->file->eof()) {
            return null;
        }

        return $this->file->fgets();
    }

    private function normalizeLineBreaks(string $value): string
    {
        return \str_replace(["\r\n", "\r"], "\n", $value);
    }

    /**
     * @return array<int, string|null>
     */
    private function parseRecord(string $line): array
    {
        return $this->csvParser->parseLine(
            $line,
            $this->delimiter,
            $this->enclosure,
            $this->escape,
            $this->expectedColumnCount,
        );
    }

    /**
     * @param array<int, string|null> $parsed
     */
    private function quotedFieldContinues(string $line, array $parsed): bool
    {
        if (null === $this->expectedColumnCount || \count($parsed) === $this->expectedColumnCount || '' === $this->enclosure) {
            return false;
        }

        return 1 === substr_count($line, $this->enclosure) % 2;
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
