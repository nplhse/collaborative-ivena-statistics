<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class GetPlatformAllocationCountsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(\DateTimeImmutable $since): PlatformAllocationCounts
    {
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*)::int FROM allocation_stats_projection');
        $last30Days = (int) $this->connection->fetchOne(
            'SELECT COUNT(*)::int FROM allocation_stats_projection WHERE created_at >= :since',
            ['since' => $since],
            ['since' => Types::DATETIME_IMMUTABLE],
        );

        return new PlatformAllocationCounts($total, $last30Days);
    }
}
