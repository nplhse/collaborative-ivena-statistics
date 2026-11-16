<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Import;

use App\Encounter\Contract\Schema\EncounterField;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Processes the rows of one encounter schema inside the shared import workflow (ADR 037).
 *
 * The core reads and validates the file against the schema's field contract, checks the key generation
 * and the key check value, records rejects, counts and coverage, and owns the transaction. The handler
 * maps rows and writes and removes its own records.
 */
#[AutoconfigureTag(self::TAG)]
interface EncounterSchemaHandlerInterface
{
    public const string TAG = 'encounter.schema_handler';

    /**
     * Schema name as in the manifest, the same as the schema's EncounterDimensionInterface::schema().
     */
    public function schema(): string;

    /**
     * Whether the schema creates encounters (`ed_visit`) instead of attaching its records to existing
     * ones. Imports of attaching schemas are deleted first when all imports of a hospital are deleted.
     */
    public function createsEncounters(): bool;

    /**
     * Maps one data row. The cells are in header order of $fields; the core has already checked the
     * header. Never keep or return cell values in errors.
     *
     * @param list<EncounterField> $fields
     * @param list<string>         $cells
     */
    public function mapRow(int $line, array $fields, array $cells): RowMappingResult;

    /**
     * Writes the valid rows of one file inside the core's transaction. Known records are skipped (they
     * count as duplicates). Must not commit, roll back or flush other work than its own.
     *
     * @param list<EncounterRecordRow> $rows rows returned by mapRow() for this file
     */
    public function write(SchemaWriteContext $context, array $rows): SchemaWriteResult;

    /**
     * Removes the records the import created (import deletion and the cleanup before a reprocess);
     * returns the number of removed records.
     */
    public function removeForImport(int $importId): int;

    /**
     * Attaches one of its records that is still without encounter to $encounterId, inside the caller's
     * transaction (manual decision or retry, #1307). Updates only an existing record whose encounter is
     * still empty; returns whether exactly that record was updated, and false when the record is gone or
     * already attached. A foreign key violation (the encounter was deleted meanwhile) is passed on.
     *
     * $recordId is the id the handler gave EncounterAssignmentsInterface::recordPending(). A schema that
     * creates encounters and never keeps pending records returns false.
     */
    public function attach(string $recordId, int $encounterId): bool;
}
