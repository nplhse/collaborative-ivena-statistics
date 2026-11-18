<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Import;

/**
 * A schema handler that flags inserted records as possible duplicates of earlier ones and lists them on the
 * import detail page (`ed_visit`, ADR 029, #1375). The core shows the list in the encounter section of the
 * import; handlers without this interface have none. Never cell values, case keys or timestamps.
 */
interface SuspectedDuplicateReaderInterface extends EncounterSchemaHandlerInterface
{
    /**
     * @return list<array{line: int, difference_minutes: int, anchor_source: string, existing_anchor_source: string, existing_import_id: int, existing_import_name: string}> by line
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); the core calls it.
     */
    public function suspectedDuplicates(int $importId, int $limit): array;
}
