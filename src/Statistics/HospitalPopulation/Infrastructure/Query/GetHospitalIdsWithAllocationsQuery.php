<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final readonly class GetHospitalIdsWithAllocationsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<int>
     */
    public function __invoke(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT hospital_id FROM allocation_stats_projection ORDER BY hospital_id',
        );

        return array_map(static fn (int|string $id): int => (int) $id, $ids);
    }
}
