<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Reads dispatch areas with their districts and geometries for the map.
 */
final readonly class DispatchAreaShapeQuery
{
    private const string FROM = 'FROM dispatch_area a
             JOIN state s ON s.id = a.state_id
             LEFT JOIN district d ON d.dispatch_area_id = a.id
             LEFT JOIN district_geometry g ON g.district_id = d.id';

    private const string HAS_HOSPITAL = 'EXISTS (SELECT 1 FROM hospital h WHERE h.dispatch_area_id = a.id)';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Changes with everything a shape is built from, as soon as a change is committed.
     */
    public function fingerprint(): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT md5(COALESCE(string_agg(part, chr(10) ORDER BY part), \'\')) FROM (
                 SELECT concat_ws(chr(31), a.id, a.name, s.name, '.self::HAS_HOSPITAL.', COALESCE(d.ags, \'\'), COALESCE(d.name, \'\'), COALESCE(d.kind, \'\'),
                                  COALESCE(md5(g.geometry), \'\'), COALESCE(g.geometry_attribution, \'\')) AS part
                 '.self::FROM.'
             ) parts',
        );
    }

    /**
     * @return list<array{id: int, name: string, state_name: string, has_hospital: bool, ags: string|null, district_name: string|null, district_kind: string|null, geometry: string|null, attribution: string|null}>
     */
    public function rows(): array
    {
        /** @var list<array{id: int, name: string, state_name: string, has_hospital: bool, ags: string|null, district_name: string|null, district_kind: string|null, geometry: string|null, attribution: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.id, a.name, s.name AS state_name, '.self::HAS_HOSPITAL.' AS has_hospital,
                    d.ags, d.name AS district_name, d.kind AS district_kind, g.geometry, g.geometry_attribution AS attribution
             '.self::FROM.'
             ORDER BY a.name, a.id, d.ags',
        );

        return $rows;
    }
}
