<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\UI\Http\DTO\HospitalQueryParametersDTO;
use App\Shared\Infrastructure\Pagination\Paginator;
use App\Shared\Infrastructure\Repository\PublicIdRepositoryTrait;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Hospital>
 */
final class HospitalRepository extends ServiceEntityRepository implements HospitalLookupInterface
{
    use PublicIdRepositoryTrait;

    public function __construct(
        ManagerRegistry $registry,
        private readonly HospitalAccessGrantRepository $hospitalAccessGrantRepository,
    ) {
        parent::__construct($registry, Hospital::class);
    }

    #[\Override]
    public function findById(int $id): ?Hospital
    {
        $entity = $this->find($id);

        return $entity instanceof Hospital ? $entity : null;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    #[\Override]
    public function findNamesByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{id: int|string, name: string}> $rows */
        $rows = $this->createQueryBuilder('h')
            ->select('h.id', 'h.name')
            ->andWhere('h.id IN (:ids)')
            ->setParameter('ids', array_values(array_unique($ids)))
            ->getQuery()
            ->getArrayResult();

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['id']] = $row['name'];
        }

        return $names;
    }

    public function countParticipating(): int
    {
        return (int) $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->andWhere('h.isParticipating = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getQueryBuilderForAccessibleHospitals(User $user): QueryBuilder
    {
        return $this->getQueryBuilderForHospitalsWithPermission($user, HospitalPermission::View);
    }

    public function getQueryBuilderForHospitalsWithPermission(User $user, HospitalPermission $permission): QueryBuilder
    {
        $qb = $this->createQueryBuilder('h')
            ->orderBy('h.name', 'ASC');

        if (\in_array(UserRole::ADMIN, $user->getRoles(), true)) {
            return $qb;
        }

        $grantHospitalIds = $this->hospitalAccessGrantRepository->findHospitalIdsForUserWithPermission($user, $permission);
        $userId = $user->getId();
        if (null === $userId) {
            return $qb->andWhere('1 = 0');
        }

        if ([] === $grantHospitalIds) {
            return $qb
                ->andWhere('IDENTITY(h.owner) = :userId')
                ->setParameter('userId', $userId);
        }

        return $qb
            ->andWhere('IDENTITY(h.owner) = :userId OR h.id IN (:grantHospitalIds)')
            ->setParameter('userId', $userId)
            ->setParameter('grantHospitalIds', $grantHospitalIds);
    }

    /**
     * Zählt Krankenhäuser wie {@see getQueryBuilderForAccessibleHospitals}, aber ohne ORDER BY — für Aggregate mit PostgreSQL.
     */
    public function countAccessibleHospitals(User $user): int
    {
        if (\in_array(UserRole::ADMIN, $user->getRoles(), true)) {
            return (int) $this->createQueryBuilder('h')
                ->select('COUNT(h.id)')
                ->getQuery()
                ->getSingleScalarResult();
        }

        return \count($this->getQueryBuilderForHospitalsWithPermission($user, HospitalPermission::Statistics)
            ->select('h.id')
            ->getQuery()
            ->getSingleColumnResult());
    }

    /**
     * @return list<Hospital>
     */
    public function findOwnedByUser(User $user): array
    {
        /** @var list<Hospital> $hospitals */
        $hospitals = $this->createQueryBuilder('h')
            ->innerJoin('h.owner', 'o')
            ->andWhere('o = :user')
            ->setParameter('user', $user->getId())
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $hospitals;
    }

    public function getHospitalListPaginator(HospitalQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('h')
            ->addSelect('(CASE WHEN h.updatedAt IS NOT NULL THEN h.updatedAt ELSE h.createdAt END) AS HIDDEN sortDate')
            ->leftJoin(
                State::class,
                's',
                Join::WITH,
                'h.state = s.id'
            )
            ->leftJoin(
                DispatchArea::class,
                'da',
                Join::WITH,
                'h.dispatchArea = da.id'
            )
        ;

        $field = match ($queryParametersDTO->sortBy) {
            'dispatchArea' => 'da.name',
            'state' => 's.name',
            'id' => 'h.id',
            'name' => 'h.name',
            'location' => 'h.location',
            'tier' => 'h.tier',
            'size' => 'h.size',
            'lastChange' => 'h.updatedAt',
            default => 'h.name',
        };

        if (null !== $queryParametersDTO->tier && '' !== $queryParametersDTO->tier) {
            $qb->andWhere('h.tier = :tier')
                ->setParameter('tier', HospitalTier::from($queryParametersDTO->tier));
        }

        if (null !== $queryParametersDTO->location && '' !== $queryParametersDTO->location) {
            $qb->andWhere('h.location = :location')
                ->setParameter('location', HospitalLocation::from($queryParametersDTO->location));
        }

        if (null !== $queryParametersDTO->size && '' !== $queryParametersDTO->size) {
            $qb->andWhere('h.size = :size')
                ->setParameter('size', HospitalSize::from($queryParametersDTO->size));
        }

        if (null !== $queryParametersDTO->state) {
            $qb->andWhere('s.id = :stateId')
                ->setParameter('stateId', $queryParametersDTO->state);
        }

        if (null !== $queryParametersDTO->dispatchArea) {
            $qb->andWhere('da.id = :dispatchAreaId')
                ->setParameter('dispatchAreaId', $queryParametersDTO->dispatchArea);
        }

        if (null !== $queryParametersDTO->participating && '' !== $queryParametersDTO->participating) {
            $qb->andWhere('h.isParticipating = :participating')
                ->setParameter(
                    'participating',
                    filter_var($queryParametersDTO->participating, FILTER_VALIDATE_BOOLEAN)
                );
        }

        $qb->orderBy($field, $queryParametersDTO->orderBy);

        if (null !== $queryParametersDTO->search) {
            $qb->andWhere($qb->expr()->like('h.name', ':search'))
                ->setParameter('search', '%'.$queryParametersDTO->search.'%')
            ;
        }

        return new Paginator($qb)->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function findAccessibleHospitalSummaries(User $user): array
    {
        $rows = $this->getQueryBuilderForAccessibleHospitals($user)
            ->select('h.id AS id', 'h.name AS name')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function findAccessibleParticipatingHospitalSummaries(User $user, HospitalPermission $permission = HospitalPermission::Statistics): array
    {
        $rows = $this->getQueryBuilderForHospitalsWithPermission($user, $permission)
            ->andWhere('h.isParticipating = true')
            ->select('h.id AS id', 'h.name AS name')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function findExportableHospitalSummaries(User $user): array
    {
        $rows = $this->getQueryBuilderForHospitalsWithPermission($user, HospitalPermission::Export)
            ->select('h.id AS id', 'h.name AS name')
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }

        return $out;
    }

    /**
     * @return list<Hospital>
     */
    public function findParticipatingWithOwner(): array
    {
        /** @var list<Hospital> $hospitals */
        $hospitals = $this->createQueryBuilder('h')
            ->addSelect('o')
            ->innerJoin('h.owner', 'o')
            ->andWhere('h.isParticipating = true')
            ->orderBy('h.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $hospitals;
    }
}
