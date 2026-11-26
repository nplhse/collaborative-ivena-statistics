<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Reads and writes the counted-access flag of stored raw requests for the backfill (#950).
 */
final readonly class AnalyticsAccessClassificationStore
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Distinct combinations of the stored fields the classification depends on, for rows that
     * have not been classified yet.
     *
     * @return list<array{routeName: string|null, httpStatus: int, deviceType: string, rowCount: int}>
     */
    public function findUnclassifiedGroups(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT route_name, http_status, device_type, COUNT(*) AS row_count
                FROM analytics_request
                WHERE counted_access IS NULL
                GROUP BY route_name, http_status, device_type
                ORDER BY route_name, http_status, device_type
                SQL,
        );

        $groups = [];
        foreach ($rows as $row) {
            $routeName = $row['route_name'] ?? null;
            $groups[] = [
                'routeName' => \is_string($routeName) ? $routeName : null,
                'httpStatus' => (int) $row['http_status'],
                'deviceType' => (string) $row['device_type'],
                'rowCount' => (int) $row['row_count'],
            ];
        }

        return $groups;
    }

    /**
     * Sets the flag on all still unclassified rows of one group; returns the number of rows.
     */
    public function classifyGroup(?string $routeName, int $httpStatus, string $deviceType, bool $countedAccess): int
    {
        return $this->connection->executeStatement(
            <<<'SQL'
                UPDATE analytics_request
                SET counted_access = :counted
                WHERE counted_access IS NULL
                  AND route_name IS NOT DISTINCT FROM CAST(:route AS VARCHAR)
                  AND http_status = :status
                  AND device_type = :device
                SQL,
            [
                'counted' => $countedAccess,
                'route' => $routeName,
                'status' => $httpStatus,
                'device' => $deviceType,
            ],
            [
                'counted' => Types::BOOLEAN,
                'route' => Types::STRING,
                'status' => Types::INTEGER,
                'device' => Types::STRING,
            ],
        );
    }
}
