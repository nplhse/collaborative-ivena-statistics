<?php

declare(strict_types=1);

namespace App\Query;

use Doctrine\DBAL\Connection;

final class HospitalCompositionQuery
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function countHospitals(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM hospital');
    }

    public function countParticipantHospitals(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM hospital WHERE is_participating = TRUE'
        );
    }

    public function countAllocations(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM allocation');
    }

    public function countParticipantAllocations(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM allocation a
             JOIN hospital h ON a.hospital_id = h.id
             WHERE h.is_participating = TRUE'
        );
    }

    /**
     * Gruppierung nach Tier.
     *
     * @return array<int, array<string, mixed>>
     */
    public function aggregateByTier(): array
    {
        return $this->aggregateByColumn('tier');
    }

    /**
     * Gruppierung nach Location.
     *
     * @return array<int, array<string, mixed>>
     */
    public function aggregateByLocation(): array
    {
        return $this->aggregateByColumn('location');
    }

    /**
     * Gruppierung nach Size.
     *
     * @return array<int, array<string, mixed>>
     */
    public function aggregateBySize(): array
    {
        return $this->aggregateByColumn('size');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function aggregateByColumn(string $column): array
    {
        // 1) Hospital-Basis-Stats (Anzahl, Teilnehmer, Betten-Statistik)
        $hospitalSql = <<<SQL
        SELECT
            h.%1\$s AS group_key,
            h.%1\$s AS group_label,
            COUNT(*) AS hospital_count,
            COUNT(*) FILTER (WHERE h.is_participating = TRUE) AS participant_hospital_count,
            AVG(h.beds) AS avg_beds,
            STDDEV_POP(h.beds) AS sd_beds,
            VAR_POP(h.beds) AS var_beds
        FROM hospital h
        WHERE h.%1\$s IS NOT NULL
        GROUP BY h.%1\$s
        ORDER BY h.%1\$s
        SQL;

        $hospitalRows = $this->connection->fetchAllAssociative(
            sprintf($hospitalSql, $column)
        );

        // 2) Allocation-Stats pro Gruppe
        $allocationSql = <<<SQL
        SELECT
            h.%1\$s AS group_key,
            COUNT(a.id) AS allocation_count,
            COUNT(a.id) FILTER (WHERE h.is_participating = TRUE) AS participant_allocation_count
        FROM hospital h
        LEFT JOIN allocation a ON a.hospital_id = h.id
        WHERE h.%1\$s IS NOT NULL
        GROUP BY h.%1\$s
        ORDER BY h.%1\$s
        SQL;

        $allocationRows = $this->connection->fetchAllAssociative(
            sprintf($allocationSql, $column)
        );

        // 3) Allocation-Daten nach group_key indexieren
        $allocIndex = [];
        foreach ($allocationRows as $row) {
            $allocIndex[(string) $row['group_key']] = $row;
        }

        // 4) Hospital- und Allocation-Daten zusammenführen
        $result = [];

        foreach ($hospitalRows as $row) {
            $key = (string) $row['group_key'];

            $alloc = $allocIndex[$key] ?? [
                'allocation_count' => 0,
                'participant_allocation_count' => 0,
            ];

            $result[] = [
                'group_key'                   => $key,
                'group_label'                 => $row['group_label'],
                'hospital_count'              => (int) $row['hospital_count'],
                'participant_hospital_count'  => (int) $row['participant_hospital_count'],
                'avg_beds'                    => $row['avg_beds'] !== null ? (float) $row['avg_beds'] : null,
                'sd_beds'                     => $row['sd_beds'] !== null ? (float) $row['sd_beds'] : null,
                'var_beds'                    => $row['var_beds'] !== null ? (float) $row['var_beds'] : null,
                'allocation_count'            => (int) $alloc['allocation_count'],
                'participant_allocation_count'=> (int) $alloc['participant_allocation_count'],
            ];
        }

        return $result;
    }
}
