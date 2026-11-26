<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

use App\Encounter\Domain\Enum\EncounterFileRejection;

/**
 * Splits a canonical exchange file into manifest, header and rows: UTF-8, delimiter `;`, LF or CRLF
 * (docs/04-features/encounter/exchange-format.md).
 *
 * A file whose first line is not an encounter manifest was not written by the data preparation tool;
 * it is treated as a raw HIS export (ADR 026) and rejected before any cell is looked at.
 */
final class EncounterFileReader
{
    public const string MANIFEST_PREFIX = '#encounter-exchange';
    public const string DELIMITER = ';';

    /**
     * @throws EncounterFileRejectedException
     */
    public function read(string $content): EncounterFile
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $lines = preg_split('/\r\n|\n|\r/', $content);
        if (false === $lines) {
            throw new EncounterFileRejectedException(EncounterFileRejection::RawExport);
        }

        $manifestLine = $lines[0];
        if (!str_starts_with($manifestLine, self::MANIFEST_PREFIX.self::DELIMITER)) {
            throw new EncounterFileRejectedException(EncounterFileRejection::RawExport);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new EncounterFileRejectedException(EncounterFileRejection::EncodingInvalid);
        }

        $header = $this->cells($lines[1] ?? '');
        $rows = [];
        $count = \count($lines);
        for ($index = 2; $index < $count; ++$index) {
            if ('' === $lines[$index]) {
                continue; // trailing newline, empty lines
            }

            $rows[$index + 1] = $this->cells($lines[$index]);
        }

        return new EncounterFile($manifestLine, $header, $rows);
    }

    /**
     * @return list<string>
     */
    private function cells(string $line): array
    {
        return array_map(
            static fn (?string $cell): string => trim((string) $cell),
            str_getcsv($line, self::DELIMITER, '"', ''),
        );
    }
}
