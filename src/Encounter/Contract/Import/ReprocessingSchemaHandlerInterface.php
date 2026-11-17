<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Import;

/**
 * A schema handler that keeps unchanged records when its import is reprocessed (#1300, ADR 029): instead of
 * removing every record of the import and writing the file again, it reconciles by its record identity (for
 * `ed_visit` the visit fingerprint). A record whose identity is still in the file keeps its id and encounter
 * and gets the file's values; records no longer in the file are removed; new ones are written.
 *
 * Runs inside the core's write transaction, after the core removed what belongs to the import as a whole
 * (rejects, counters, pending assignments and their decisions). A failed run commits nothing. Handlers without
 * this interface are reprocessed by removeForImport() and write() in the same transaction.
 */
interface ReprocessingSchemaHandlerInterface extends EncounterSchemaHandlerInterface
{
    /**
     * @param list<EncounterRecordRow> $rows rows returned by mapRow() for this file
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); the core calls it.
     */
    public function reprocess(SchemaWriteContext $context, array $rows): SchemaWriteResult;
}
