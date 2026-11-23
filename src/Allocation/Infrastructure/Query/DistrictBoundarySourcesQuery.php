<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

use App\Allocation\Application\Contracts\DistrictBoundarySourcesInterface;
use Doctrine\DBAL\Connection;

/**
 * @psalm-suppress UnusedClass Wired via DistrictBoundarySourcesInterface autowiring.
 */
final readonly class DistrictBoundarySourcesQuery implements DistrictBoundarySourcesInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function all(): array
    {
        /** @var list<array{provider: string|null, licence: string|null, attribution: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT geometry_provider AS provider, geometry_licence AS licence, geometry_attribution AS attribution
             FROM district_geometry
             WHERE geometry IS NOT NULL AND geometry_attribution IS NOT NULL
             ORDER BY attribution, provider, licence',
        );

        return $rows;
    }
}
