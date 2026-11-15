<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Import;

/**
 * What a schema handler wrote. Valid rows that were not inserted count as duplicates in the report.
 *
 * $insertedDays (`Y-m-d`) are the days with new encounters; the core announces them after the commit
 * (EncountersImported) so that matching can run for them. Schemas that only attach records to existing
 * encounters return none.
 */
readonly class SchemaWriteResult
{
    /**
     * @param list<string> $insertedDays
     */
    public function __construct(
        public int $inserted,
        public array $insertedDays = [],
    ) {
    }
}
