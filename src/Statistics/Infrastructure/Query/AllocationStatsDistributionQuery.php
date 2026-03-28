<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Whitelisted GROUP BY queries on allocation_stats_projection.
 */
final readonly class AllocationStatsDistributionQuery
{
    private const array PRIMARY_COLUMNS = [
        'urgency' => 'urgency_code',
        'gender' => 'gender_code',
    ];

    private const array GROUP_COLUMNS = [
        'hospital_tier' => 'hospital_tier_code',
        'hospital_location' => 'hospital_location_code',
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function countRows(): int
    {
        $n = $this->connection->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection');

        return (int) $n;
    }

    /**
     * @return list<array{pk: int, gk: int|null, value: int}>
     */
    public function fetchAggregated(
        string $primaryDimension,
        ?string $groupDimension,
    ): array {
        $primaryCol = self::PRIMARY_COLUMNS[$primaryDimension] ?? null;
        if (!\is_string($primaryCol)) {
            throw new \InvalidArgumentException('Unknown primary distribution dimension: '.$primaryDimension);
        }

        if (null === $groupDimension || '' === $groupDimension) {
            $sql = 'SELECT '.$primaryCol.' AS pk, COUNT(*) AS value FROM allocation_stats_projection'
                .' GROUP BY '.$primaryCol.' ORDER BY '.$primaryCol;

            $raw = $this->connection->fetchAllAssociative($sql);

            $out = [];
            foreach ($raw as $row) {
                $out[] = [
                    'pk' => (int) $row['pk'],
                    'gk' => null,
                    'value' => (int) $row['value'],
                ];
            }

            return $out;
        }

        $groupCol = self::GROUP_COLUMNS[$groupDimension] ?? null;
        if (!\is_string($groupCol)) {
            throw new \InvalidArgumentException('Unknown group distribution dimension: '.$groupDimension);
        }

        $sql = 'SELECT '.$primaryCol.' AS pk, '.$groupCol.' AS gk, COUNT(*) AS value FROM allocation_stats_projection'
            .' GROUP BY '.$primaryCol.', '.$groupCol.' ORDER BY '.$primaryCol.', '.$groupCol;

        $raw = $this->connection->fetchAllAssociative($sql);

        $out = [];
        foreach ($raw as $row) {
            $gkRaw = $row['gk'] ?? null;
            $out[] = [
                'pk' => (int) $row['pk'],
                'gk' => null === $gkRaw || '' === $gkRaw ? null : (int) $gkRaw,
                'value' => (int) $row['value'],
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function primaryDimensions(): array
    {
        return array_keys(self::PRIMARY_COLUMNS);
    }

    /**
     * @return list<string>
     */
    public static function groupDimensions(): array
    {
        return array_keys(self::GROUP_COLUMNS);
    }
}
