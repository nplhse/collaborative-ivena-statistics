<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Projection;

use App\Statistics\Application\Contract\ProjectionOverviewChangeDetectorInterface;
use Doctrine\DBAL\Connection;

final readonly class ProjectionOverviewChangeDetector implements ProjectionOverviewChangeDetectorInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function willIntroduceNewHospitals(int $importId): bool
    {
        return (bool) $this->connection->fetchOne(
            <<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM allocation a
    WHERE a.import_id = :importId
      AND NOT EXISTS (
          SELECT 1
          FROM allocation_stats_projection p
          WHERE p.hospital_id = a.hospital_id
      )
)
SQL,
            ['importId' => $importId],
        );
    }

    #[\Override]
    public function willRemoveHospitalsFromProjection(int $importId): bool
    {
        return (bool) $this->connection->fetchOne(
            <<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM allocation_stats_projection p
    WHERE p.import_id = :importId
      AND NOT EXISTS (
          SELECT 1
          FROM allocation_stats_projection p2
          WHERE p2.hospital_id = p.hospital_id
            AND p2.import_id <> :importId
      )
)
SQL,
            ['importId' => $importId],
        );
    }
}
