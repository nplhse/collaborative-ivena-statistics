<?php

declare(strict_types=1);

namespace App\Query;

use Doctrine\DBAL\Connection;

final class HospitalCubeQuery
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function fetchCubeRows(): array
    {
        $sql = <<<SQL
            SELECT
                h.id,
                h.tier,
                h.location,
                h.size,
                s.name         AS state,
                d.name         AS dispatch_area,
                h.is_participating,
                h.beds,
                COUNT(a.id)    AS allocation_count
            FROM hospital h
            LEFT JOIN state s ON h.state_id = s.id
            LEFT JOIN dispatch_area d ON h.dispatch_area_id = d.id
            LEFT JOIN allocation a ON a.hospital_id = h.id
            GROUP BY h.id, h.tier, h.location, h.size, s.name, d.name, h.is_participating, h.beds;
            SQL;

        $rows = $this->db->fetchAllAssociative($sql);

        return array_map(static fn (array $row) => [
            'tier' => $row['tier'],
            'location' => $row['location'],
            'size' => $row['size'],
            'state' => $row['state'],
            'dispatchArea' => $row['dispatch_area'],
            'isParticipating' => (bool) $row['is_participating'],
            'beds' => (int) $row['beds'],
            'allocationCount' => (int) $row['allocation_count'],
        ], $rows);
    }
}
