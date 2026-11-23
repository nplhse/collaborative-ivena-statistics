<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

use App\Allocation\Application\Geo\DTO\DispatchAreaShapeStatus;
use Doctrine\DBAL\Connection;

/**
 * Reads only whether a geometry exists, never the polygons.
 */
final readonly class DispatchAreaShapeStatusQuery
{
    /** Official state keys, for the hint on districts of another state. */
    private const array STATE_CODES = [
        'Schleswig-Holstein' => '01',
        'Hamburg' => '02',
        'Niedersachsen' => '03',
        'Bremen' => '04',
        'Nordrhein-Westfalen' => '05',
        'Hessen' => '06',
        'Rheinland-Pfalz' => '07',
        'Baden-Württemberg' => '08',
        'Bayern' => '09',
        'Saarland' => '10',
        'Berlin' => '11',
        'Brandenburg' => '12',
        'Mecklenburg-Vorpommern' => '13',
        'Sachsen' => '14',
        'Sachsen-Anhalt' => '15',
        'Thüringen' => '16',
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<DispatchAreaShapeStatus>
     */
    public function all(): array
    {
        return $this->load(null);
    }

    public function forDispatchArea(int $dispatchAreaId): ?DispatchAreaShapeStatus
    {
        return $this->load($dispatchAreaId)[0] ?? null;
    }

    /**
     * @return list<DispatchAreaShapeStatus>
     */
    private function load(?int $dispatchAreaId): array
    {
        /** @var list<array{id: int, name: string, state_name: string, has_allocations: bool, ags: string|null, state_code: string|null, district_name: string|null, has_geometry: bool}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.id, a.name, s.name AS state_name,
                    EXISTS (SELECT 1 FROM allocation x WHERE x.dispatch_area_id = a.id) AS has_allocations,
                    d.ags, d.state_code, d.name AS district_name,
                    (g.geometry IS NOT NULL) AS has_geometry
             FROM dispatch_area a
             JOIN state s ON s.id = a.state_id
             LEFT JOIN district d ON d.dispatch_area_id = a.id
             LEFT JOIN district_geometry g ON g.district_id = d.id
             WHERE (CAST(:id AS INT) IS NULL OR a.id = :id)
             ORDER BY a.name, a.id, d.ags',
            ['id' => $dispatchAreaId],
        );

        /** @var array<int, array{name: string, hasAllocations: bool, count: int, missing: list<string>, other: list<string>}> $areas */
        $areas = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $areas[$id] ??= ['name' => $row['name'], 'hasAllocations' => (bool) $row['has_allocations'], 'count' => 0, 'missing' => [], 'other' => []];

            if (null === $row['ags']) {
                continue;
            }

            ++$areas[$id]['count'];
            $label = sprintf('%s %s', $row['ags'], (string) $row['district_name']);
            if (!$row['has_geometry']) {
                $areas[$id]['missing'][] = $label;
            }

            $stateCode = self::STATE_CODES[$row['state_name']] ?? null;
            if (null !== $stateCode && $stateCode !== $row['state_code']) {
                $areas[$id]['other'][] = $label;
            }
        }

        $statuses = [];
        foreach ($areas as $area) {
            $statuses[] = new DispatchAreaShapeStatus($area['name'], $area['hasAllocations'], $area['count'], $area['missing'], $area['other']);
        }

        return $statuses;
    }
}
