<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

/**
 * A canonical exchange file split into manifest line, header and data rows (ADR 026). Only lives in
 * memory while a run processes the file.
 */
final readonly class EncounterFile
{
    /** Physical line of the first data row: line 1 is the manifest, line 2 the header. */
    public const int FIRST_DATA_LINE = 3;

    /**
     * @param list<string>             $header
     * @param array<int, list<string>> $rows   cells by physical line number
     */
    public function __construct(
        public string $manifestLine,
        public array $header,
        public array $rows,
    ) {
    }
}
