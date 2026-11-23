<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Application\Geo\DTO\DistrictBoundaryStatus;
use App\Allocation\Domain\Entity\District;
use App\Allocation\Domain\Entity\DistrictGeometry;
use App\Allocation\Domain\Enum\DistrictBoundaryFetchFailure;
use App\Allocation\Domain\Enum\DistrictBoundaryFetchStatus;
use App\Shared\Infrastructure\Repository\PublicIdRepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<District>
 */
final class DistrictRepository extends ServiceEntityRepository
{
    use PublicIdRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, District::class);
    }

    /**
     * Loads the boundary on demand; `District` has no relation to it, so lists never hydrate polygons.
     */
    public function findGeometry(District $district): ?DistrictGeometry
    {
        return $this->getEntityManager()->find(DistrictGeometry::class, $district->getId());
    }

    /**
     * Starts a new attempt if the geometry is missing and no live attempt runs.
     */
    public function startBoundaryFetch(int $districtId, string $attemptId, \DateTimeImmutable $now, \DateTimeImmutable $staleBefore): bool
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement('INSERT INTO district_geometry (district_id) VALUES (?) ON CONFLICT (district_id) DO NOTHING', [$districtId]);

        return 1 === $connection->executeStatement(
            "UPDATE district_geometry
             SET fetch_status = 'running', fetch_attempt_id = :attempt, fetch_error = NULL, fetch_requested_at = :now
             WHERE district_id = :district AND geometry IS NULL
               AND (fetch_status IN ('idle', 'failed')
                    OR (fetch_status = 'running' AND (fetch_requested_at IS NULL OR fetch_requested_at < :staleBefore)))",
            ['attempt' => $attemptId, 'now' => $now, 'district' => $districtId, 'staleBefore' => $staleBefore],
            ['now' => Types::DATETIME_IMMUTABLE, 'staleBefore' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function isCurrentBoundaryFetch(int $districtId, string $attemptId): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT 1 FROM district_geometry WHERE district_id = ? AND fetch_attempt_id = ? AND fetch_status = 'running' AND geometry IS NULL",
            [$districtId, $attemptId],
        );
    }

    /**
     * Writes the result only if this attempt is still current and no geometry exists by now.
     */
    public function completeBoundaryFetch(
        int $districtId,
        string $attemptId,
        string $geometry,
        string $provider,
        ?string $licence,
        ?string $attribution,
        \DateTimeImmutable $fetchedAt,
    ): bool {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE district_geometry
             SET geometry = :geometry, geometry_provider = :provider, geometry_licence = :licence,
                 geometry_attribution = :attribution, geometry_fetched_at = :fetchedAt,
                 fetch_status = 'idle', fetch_attempt_id = NULL, fetch_error = NULL, fetch_requested_at = NULL
             WHERE district_id = :district AND fetch_attempt_id = :attempt AND fetch_status = 'running' AND geometry IS NULL",
            [
                'geometry' => $geometry,
                'provider' => $provider,
                'licence' => $licence,
                'attribution' => $attribution,
                'fetchedAt' => $fetchedAt,
                'district' => $districtId,
                'attempt' => $attemptId,
            ],
            ['fetchedAt' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function failBoundaryFetch(int $districtId, string $attemptId, DistrictBoundaryFetchFailure $failure): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE district_geometry SET fetch_status = 'failed', fetch_error = ?
             WHERE district_id = ? AND fetch_attempt_id = ? AND fetch_status = 'running' AND geometry IS NULL",
            [$failure->value, $districtId, $attemptId],
        );
    }

    /**
     * Reads the state without loading the polygon.
     */
    public function boundaryStatus(int $districtId, \DateTimeImmutable $now): DistrictBoundaryStatus
    {
        /** @var array{has_geometry: bool, fetch_status: string, fetch_error: string|null, fetch_requested_at: string|null, geometry_provider: string|null, geometry_licence: string|null, geometry_attribution: string|null, geometry_fetched_at: string|null}|false $row */
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT (geometry IS NOT NULL) AS has_geometry, fetch_status, fetch_error, fetch_requested_at,
                    geometry_provider, geometry_licence, geometry_attribution, geometry_fetched_at
             FROM district_geometry WHERE district_id = ?',
            [$districtId],
        );

        if (false === $row) {
            return new DistrictBoundaryStatus(DistrictBoundaryStatus::MISSING);
        }

        return DistrictBoundaryStatus::from(
            (bool) $row['has_geometry'],
            DistrictBoundaryFetchStatus::from($row['fetch_status']),
            $row['fetch_error'],
            null === $row['fetch_requested_at'] ? null : new \DateTimeImmutable($row['fetch_requested_at']),
            $now,
            $row['geometry_provider'],
            $row['geometry_licence'],
            $row['geometry_attribution'],
            null === $row['geometry_fetched_at'] ? null : new \DateTimeImmutable($row['geometry_fetched_at']),
        );
    }
}
