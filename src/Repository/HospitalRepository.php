<?php

namespace App\Repository;

use App\DataTransferObjects\HospitalQueryParametersDTO;
use App\Entity\DispatchArea;
use App\Entity\Hospital;
use App\Entity\State;
use App\Enum\HospitalLocation;
use App\Enum\HospitalSize;
use App\Enum\HospitalTier;
use App\Shared\Infrastructure\Pagination\Paginator;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Hospital>
 */
final class HospitalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Hospital::class);
    }

    public function getQueryBuilderForAccessibleHospitals(User $user): QueryBuilder
    {
        $qb = $this->createQueryBuilder('h')
            ->orderBy('h.name', 'ASC');

        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $qb;
        }

        return $qb
            ->innerJoin('h.owner', 'o')
            ->andWhere('o = :user')
            ->setParameter('user', $user->getId());
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
            'dispatchArea' => 'd.name',
            'state' => 's.name',
            default => 'h.'.$queryParametersDTO->sortBy,
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
}
