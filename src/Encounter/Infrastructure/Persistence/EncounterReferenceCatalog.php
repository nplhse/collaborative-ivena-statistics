<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/**
 * The foreign keys that keep an encounter alive (ADR 037): every single-column reference to
 * `encounter(id)` with ON DELETE RESTRICT or NO ACTION, read from the PostgreSQL catalog. Record tables of
 * attaching schemas reference encounters this way, so the catalog also finds tables of bundles that are
 * disabled but still installed in the database. Tables of the core reference encounters with CASCADE or
 * SET NULL and are not listed.
 */
final readonly class EncounterReferenceCatalog
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<array{table: string, column: string, constraint: string}>
     */
    public function restrictingReferences(): array
    {
        /** @var list<array{table: string, column: string, constraint: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.conrelid::regclass::text AS table, quote_ident(a.attname) AS column, c.conname AS constraint
             FROM pg_constraint c
             INNER JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
             WHERE c.contype = 'f' AND c.confrelid = 'encounter'::regclass
               AND c.confdeltype IN ('r', 'a') AND cardinality(c.conkey) = 1
             ORDER BY 1, 2",
        );

        return $rows;
    }

    /**
     * Number of records that reference an encounter the import created through a restricting reference.
     */
    public function attachedToImport(int $importId): int
    {
        $attached = 0;
        foreach ($this->restrictingReferences() as $reference) {
            $attached += (int) $this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s r INNER JOIN encounter e ON e.id = r.%s WHERE e.import_id = ?', $reference['table'], $reference['column']),
                [$importId],
            );
        }

        return $attached;
    }

    /**
     * Whether a database error names one of the given restricting references.
     *
     * @param list<array{table: string, column: string, constraint: string}> $references
     */
    public static function names(\Throwable $error, array $references): bool
    {
        return array_any($references, fn (array $reference): bool => str_contains($error->getMessage(), '"'.$reference['constraint'].'"'));
    }
}
