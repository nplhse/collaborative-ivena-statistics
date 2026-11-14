<?php

declare(strict_types=1);

namespace App\Encounter\Application\Catalog;

use App\Encounter\Domain\Enum\MtsCatalogKind;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One-time backfill of ADR 032 (#1157): codes already stored in `encounter` (initial triage and retriage) that
 * resolve to nothing become provisional catalog entries, as the import does for new visits. The first-seen time is
 * the creation time of the oldest stored encounter with the code. Idempotent: a second run creates nothing.
 */
final readonly class MtsCatalogFirstSeenBackfill
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MtsCatalogFirstSeenWriter $writer,
    ) {
    }

    /**
     * @return array{diagram: list<string>, indicator: list<string>} created codes per kind
     */
    public function run(): array
    {
        return $this->entityManager->wrapInTransaction(fn (): array => [
            MtsCatalogKind::Diagram->value => $this->writer->record(MtsCatalogKind::Diagram, $this->storedCodes('mts_diagram', 'retriage_diagram'), ['source' => 'backfill']),
            MtsCatalogKind::Indicator->value => $this->writer->record(MtsCatalogKind::Indicator, $this->storedCodes('mts_indicator', 'retriage_indicator'), ['source' => 'backfill']),
        ]);
    }

    /**
     * @return array<string, \DateTimeImmutable> code => creation time of the oldest encounter with it
     */
    private function storedCodes(string $initialColumn, string $retriageColumn): array
    {
        /** @var array<string, string> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllKeyValue(\sprintf(
            'SELECT code, MIN(created_at) FROM (SELECT %1$s AS code, created_at FROM encounter WHERE %1$s IS NOT NULL UNION ALL SELECT %2$s, created_at FROM encounter WHERE %2$s IS NOT NULL) AS codes GROUP BY code ORDER BY code',
            $initialColumn,
            $retriageColumn,
        ));

        $codes = [];
        foreach ($rows as $code => $createdAt) {
            $codes[(string) $code] = new \DateTimeImmutable($createdAt);
        }

        return $codes;
    }
}
