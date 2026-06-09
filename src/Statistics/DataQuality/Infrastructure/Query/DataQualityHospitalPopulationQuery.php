<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Infrastructure\Query;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\Application\Cohort\HospitalCohort;
use App\Statistics\DataQuality\Application\Contract\DataQualityHospitalPopulationReaderInterface;
use App\Statistics\DataQuality\Dto\DataQualityHospitalSnapshot;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DataQualityHospitalPopulationQuery implements DataQualityHospitalPopulationReaderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<DataQualityHospitalSnapshot>
     */
    #[\Override]
    public function fetchAll(): array
    {
        return $this->fetchWithFilters(null, null, null, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return list<DataQualityHospitalSnapshot>
     */
    #[\Override]
    public function fetchByIds(array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->fetchWithFilters($hospitalIds, null, null, null);
    }

    #[\Override]
    public function fetchByStateId(int $stateId): array
    {
        return $this->fetchWithFilters(null, $stateId, null, null);
    }

    #[\Override]
    public function fetchByDispatchAreaId(int $dispatchAreaId): array
    {
        return $this->fetchWithFilters(null, null, $dispatchAreaId, null);
    }

    #[\Override]
    public function fetchByCohort(HospitalCohort $cohort): array
    {
        $locations = array_map(
            static fn ($code) => $code->toHospitalLocation(),
            $cohort->locationCodes,
        );
        $tiers = array_map(
            static fn ($code) => $code->toHospitalTier(),
            $cohort->tierCodes,
        );

        return $this->fetchWithFilters(null, null, null, $cohort, $locations, $tiers);
    }

    /**
     * @param list<int>|null              $hospitalIds
     * @param list<HospitalLocation>|null $locations
     * @param list<HospitalTier>|null     $tiers
     *
     * @return list<DataQualityHospitalSnapshot>
     */
    private function fetchWithFilters(
        ?array $hospitalIds,
        ?int $stateId,
        ?int $dispatchAreaId,
        ?HospitalCohort $cohort,
        ?array $locations = null,
        ?array $tiers = null,
    ): array {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Hospital::class, 'h')
            ->select('h.id', 'h.size', 'h.tier', 'h.location', 'da.name AS landkreis')
            ->leftJoin('h.dispatchArea', 'da')
            ->leftJoin('h.state', 's')
            ->orderBy('h.id', 'ASC');

        if (null !== $hospitalIds) {
            $qb->andWhere('h.id IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        if (null !== $stateId) {
            $qb->andWhere('s.id = :stateId')
                ->setParameter('stateId', $stateId);
        }

        if (null !== $dispatchAreaId) {
            $qb->andWhere('da.id = :dispatchAreaId')
                ->setParameter('dispatchAreaId', $dispatchAreaId);
        }

        if ($cohort instanceof HospitalCohort) {
            $resolvedLocations = $locations ?? array_map(
                static fn ($code) => $code->toHospitalLocation(),
                $cohort->locationCodes,
            );
            $resolvedTiers = $tiers ?? array_map(
                static fn ($code) => $code->toHospitalTier(),
                $cohort->tierCodes,
            );

            $qb->andWhere('h.location IN (:locations)')
                ->andWhere('h.tier IN (:tiers)')
                ->setParameter('locations', $resolvedLocations)
                ->setParameter('tiers', $resolvedTiers);
        }

        /** @var list<array{id: int|string, size: HospitalSize|string, tier: HospitalTier|string|null, location: HospitalLocation|string, landkreis: ?string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $snapshots = [];
        foreach ($rows as $row) {
            $snapshots[] = new DataQualityHospitalSnapshot(
                (int) $row['id'],
                $this->stringValue($row['size']),
                $this->nullableStringValue($row['tier']),
                $this->stringValue($row['location']),
                $row['landkreis'] ?? 'unknown',
            );
        }

        return $snapshots;
    }

    private function stringValue(\BackedEnum|string $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : $value;
    }

    private function nullableStringValue(\BackedEnum|string|null $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return $this->stringValue($value);
    }
}
