<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Adapter;

/**
 * Parses IVENA semicolon CSV with RFC 4180 controls (enclosure ", empty escape).
 *
 * When a row contains unescaped inner ASCII quotes (e.g. STEMI / "OMI"), fgetcsv /
 * str_getcsv splits the field. Those exports wrap every cell in quotes and separate
 * fields with `";"`, so a quoted-field split recovers the original columns.
 */
final class IvenaQuotedCsvParser
{
    /**
     * @return array<int, string|null>
     */
    public function parseLine(
        string $line,
        string $delimiter,
        string $enclosure,
        string $escape,
        ?int $expectedColumnCount = null,
    ): array {
        $line = rtrim($line, "\r\n");
        if ('' === $line) {
            return [null];
        }

        $startsQuoted = str_starts_with($line, $enclosure);

        if ($startsQuoted) {
            $lenient = $this->parseLenientQuoted($line, $delimiter, $enclosure);
            if (null !== $lenient && (null === $expectedColumnCount || \count($lenient) === $expectedColumnCount)) {
                return $lenient;
            }
        }

        /** @var list<string|null> $rfc */
        $rfc = str_getcsv($line, $delimiter, $enclosure, $escape);

        return $this->stringify($rfc);
    }

    /**
     * @return list<string>|null
     */
    public function parseLenientQuoted(string $line, string $delimiter, string $enclosure): ?array
    {
        $line = rtrim($line, "\r\n");
        if ('' === $line || !str_starts_with($line, $enclosure)) {
            return null;
        }

        $separator = $enclosure.$delimiter.$enclosure;
        if ('' === $separator) {
            return null;
        }

        $parts = explode($separator, $line);
        $lastIndex = array_key_last($parts);

        $first = $parts[0];
        if (str_starts_with($first, $enclosure)) {
            $parts[0] = substr($first, 1);
        }

        $last = $parts[$lastIndex];
        if (str_ends_with($last, $enclosure)) {
            $parts[$lastIndex] = substr($last, 0, -1);
        }

        $fields = [];
        foreach ($parts as $part) {
            $fields[] = str_replace($enclosure.$enclosure, $enclosure, $part);
        }

        return $fields;
    }

    /**
     * @param list<string|null> $cells
     *
     * @return array<int, string>
     */
    private function stringify(array $cells): array
    {
        $out = [];
        foreach ($cells as $cell) {
            $out[] = $cell ?? '';
        }

        return $out;
    }
}
