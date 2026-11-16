<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Cleanup;

use App\Encounter\Application\Cleanup\EncounterCleanupResult;
use App\Encounter\Application\Contract\EncounterCleanupInterface;
use App\Encounter\Contract\Assignment\EncounterHospitalLockInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;

/**
 * Removes encounters without clinical records (#1300, ADR 037), one transaction per hospital under the
 * encounter hospital lock. Every write path that attaches a record to an existing encounter or assigns an
 * allocation takes the same lock, so a write that found an empty encounter cannot lose it to the cleanup, and
 * the cleanup never sees a half-done write.
 *
 * A clinical record is a row of any table whose foreign key to `encounter` refuses the deletion (`RESTRICT` or
 * no action), read from the database catalog: tables of disabled schema bundles count as well. Rows that
 * cascade with the encounter (the ED matching history, suspected duplicates, validation items) are no records
 * and go with it. An encounter with an IVENA assignment is removed too; the allocation stays.
 *
 * The check is part of the delete statement; the foreign keys are the last guard: if one still refuses, the
 * hospital's transaction is rolled back and counted as skipped.
 *
 * @psalm-suppress UnusedClass Wired as EncounterCleanupInterface.
 */
final readonly class EncounterCleanup implements EncounterCleanupInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private Connection $connection,
        private EncounterHospitalLockInterface $lock,
    ) {
    }

    #[\Override]
    public function run(?int $hospitalId = null): EncounterCleanupResult
    {
        $hospitals = null !== $hospitalId
            ? [$hospitalId]
            : array_map(intval(...), $this->connection->fetchFirstColumn('SELECT DISTINCT hospital_id FROM encounter ORDER BY hospital_id'));
        $condition = $this->noRecordCondition();

        $result = new EncounterCleanupResult();
        foreach ($hospitals as $id) {
            $result = $result->plus($this->cleanHospital($id, $condition));
        }

        return $result;
    }

    private function cleanHospital(int $hospitalId, string $noRecord): EncounterCleanupResult
    {
        $this->connection->beginTransaction();
        try {
            $this->lock->lock($hospitalId);
            /** @var list<array{id: int|string, allocation_id: int|string|null}> $removed */
            $removed = $this->connection->fetchAllAssociative(
                "DELETE FROM encounter e WHERE e.hospital_id = ? AND {$noRecord} RETURNING e.id, e.allocation_id",
                [$hospitalId],
            );
            $this->connection->commit();
        } catch (ForeignKeyConstraintViolationException) {
            $this->connection->rollBack();

            return new EncounterCleanupResult(hospitals: 1, skippedHospitals: 1);
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        return new EncounterCleanupResult(
            hospitals: 1,
            removed: \count($removed),
            removedWithAssignment: \count(array_filter($removed, static fn (array $row): bool => null !== $row['allocation_id'])),
        );
    }

    /**
     * `NOT EXISTS` for every single-column foreign key to `encounter` that refuses a deletion.
     */
    private function noRecordCondition(): string
    {
        /** @var list<array{table: string, column: string}> $references */
        $references = $this->connection->fetchAllAssociative(
            "SELECT c.conrelid::regclass::text AS table, quote_ident(a.attname) AS column
             FROM pg_constraint c
             INNER JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
             WHERE c.contype = 'f' AND c.confrelid = 'encounter'::regclass
               AND c.confdeltype IN ('r', 'a') AND cardinality(c.conkey) = 1
             ORDER BY 1, 2",
        );

        $conditions = array_map(
            static fn (array $reference): string => sprintf('NOT EXISTS (SELECT 1 FROM %s r WHERE r.%s = e.id)', $reference['table'], $reference['column']),
            $references,
        );

        return [] === $conditions ? 'TRUE' : implode(' AND ', $conditions);
    }
}
