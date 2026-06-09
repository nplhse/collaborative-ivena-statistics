<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final readonly class GetAllocationCountsPerHospitalQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<int, int> hospitalId => allocationCount
     */
    public function __invoke(): array
    {
        /** @var list<array{hospital_id: int|string, allocation_count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT hospital_id, COUNT(*) AS allocation_count
             FROM allocation_stats_projection
             GROUP BY hospital_id
             ORDER BY hospital_id',
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['hospital_id']] = (int) $row['allocation_count'];
        }

        return $counts;
    }
}
