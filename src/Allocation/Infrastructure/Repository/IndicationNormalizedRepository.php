<?php

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\UI\Http\DTO\IndicationQueryParametersDTO;
use App\Shared\Infrastructure\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndicationNormalized>
 */
final class IndicationNormalizedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicationNormalized::class);
    }

    public function getListPaginator(IndicationQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('i')
            ->addSelect('(CASE WHEN i.updatedAt IS NOT NULL THEN i.updatedAt ELSE i.createdAt END) AS HIDDEN sortDate')
        ;

        if ('lastChange' === $queryParametersDTO->sortBy) {
            $qb->orderBy('sortDate', $queryParametersDTO->orderBy);
        } else {
            $qb->orderBy('i.'.$queryParametersDTO->sortBy, $queryParametersDTO->orderBy);
        }

        if (null !== $queryParametersDTO->search) {
            $qb->andWhere($qb->expr()->like('LOWER(i.name)', ':search'))
                ->setParameter('search', '%'.mb_strtolower($queryParametersDTO->search).'%')
            ;
        }

        return new Paginator($qb)->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    public function getDatalist(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.id, i.name, i.code')
            ->orderBy('i.code', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $result): array {
            $label = $result['name'];
            if (!empty($result['code'])) {
                $label .= ' ('.$result['code'].')';
            }

            return ['id' => (int) $result['id'], 'label' => $label];
        }, $rows);
    }

    public function getDatalistLabelById(int $id): ?string
    {
        $result = $this->createQueryBuilder('i')
            ->select('i.name, i.code')
            ->andWhere('i.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        if (false === $result) {
            return null;
        }

        return $result['name'].(!empty($result['code']) ? ' ('.$result['code'].')' : '');
    }
}
