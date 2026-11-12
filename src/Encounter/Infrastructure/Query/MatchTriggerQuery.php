<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Import\Domain\Enum\ImportType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Which hospital-days a matcher rerun covers after an import or its deletion (ADR 029).
 */
final readonly class MatchTriggerQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Hospital and arrival days of an allocation import, or null when it is no allocation import or its
     * hospital has no encounter data (nothing to match).
     *
     * @return array{hospitalId: int, days: list<string>}|null
     */
    public function allocationImportDays(int $importId): ?array
    {
        $hospitalId = $this->connection->fetchOne(
            'SELECT hospital_id FROM import WHERE id = :import AND type = :type',
            ['import' => $importId, 'type' => ImportType::ALLOCATION->value],
            ['import' => Types::INTEGER, 'type' => Types::STRING],
        );
        if (false === $hospitalId || null === $hospitalId || !$this->hasEncounterData((int) $hospitalId)) {
            return null;
        }

        /** @var list<string> $days */
        $days = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT to_char(arrival_at, 'YYYY-MM-DD') AS day FROM allocation WHERE import_id = :import ORDER BY day",
            ['import' => $importId],
            ['import' => Types::INTEGER],
        );

        return ['hospitalId' => (int) $hospitalId, 'days' => $days];
    }

    /**
     * Hospital and the days a deletion or reprocess of the import frees for matching: arrival days of its
     * allocations and the days around its visits. Null when the hospital has no encounter data.
     *
     * @return array{hospitalId: int, days: list<string>, visitDays: list<string>}|null
     */
    public function importDays(int $importId): ?array
    {
        $hospitalId = $this->connection->fetchOne('SELECT hospital_id FROM import WHERE id = :import', ['import' => $importId], ['import' => Types::INTEGER]);
        if (false === $hospitalId || null === $hospitalId || !$this->hasEncounterData((int) $hospitalId)) {
            return null;
        }

        /** @var list<string> $days */
        $days = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT to_char(arrival_at, 'YYYY-MM-DD') AS day FROM allocation WHERE import_id = :import ORDER BY day",
            ['import' => $importId],
            ['import' => Types::INTEGER],
        );
        /** @var list<string> $visitDays */
        $visitDays = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT to_char(COALESCE(ed_admission_at, first_assessment_contact_at), 'YYYY-MM-DD') AS day FROM encounter WHERE import_id = :import ORDER BY day",
            ['import' => $importId],
            ['import' => Types::INTEGER],
        );

        return ['hospitalId' => (int) $hospitalId, 'days' => $days, 'visitDays' => $visitDays];
    }

    private function hasEncounterData(int $hospitalId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM encounter WHERE hospital_id = :hospital LIMIT 1',
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );
    }
}
