<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Infrastructure\Query;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationSnapshot;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GetHospitalPopulationQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<HospitalPopulationSnapshot>
     */
    public function __invoke(): array
    {
        /** @var list<array{
         *     id: int|string,
         *     name: string,
         *     stateId: int|string,
         *     stateName: string,
         *     dispatchAreaId: int|string,
         *     dispatchAreaName: string,
         *     latitude: float|string|null,
         *     longitude: float|string|null,
         *     beds: int|string,
         *     size: HospitalSize,
         *     careLevel: HospitalTier|null,
         *     urbanity: HospitalLocation,
         *     isParticipating: bool|int
         * }> $rows
         */
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'h.id AS id',
                'h.name AS name',
                's.id AS stateId',
                's.name AS stateName',
                'da.id AS dispatchAreaId',
                'da.name AS dispatchAreaName',
                'h.latitude AS latitude',
                'h.longitude AS longitude',
                'h.beds AS beds',
                'h.size AS size',
                'h.tier AS careLevel',
                'h.location AS urbanity',
                'h.isParticipating AS isParticipating',
            )
            ->from(Hospital::class, 'h')
            ->innerJoin('h.state', 's')
            ->innerJoin('h.dispatchArea', 'da')
            ->orderBy('s.name', 'ASC')
            ->addOrderBy('da.name', 'ASC')
            ->addOrderBy('h.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): HospitalPopulationSnapshot => new HospitalPopulationSnapshot(
                id: (int) $row['id'],
                name: $row['name'],
                stateId: (int) $row['stateId'],
                stateName: $row['stateName'],
                dispatchAreaId: (int) $row['dispatchAreaId'],
                dispatchAreaName: $row['dispatchAreaName'],
                latitude: null !== $row['latitude'] ? (float) $row['latitude'] : null,
                longitude: null !== $row['longitude'] ? (float) $row['longitude'] : null,
                beds: (int) $row['beds'],
                size: $row['size'],
                careLevel: $row['careLevel'],
                urbanity: $row['urbanity'],
                hasAllocations: false,
                isParticipating: (bool) $row['isParticipating'],
            ),
            $rows,
        );
    }
}
