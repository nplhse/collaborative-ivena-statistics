<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\UI\Http\DTO\IndicationQueryParametersDTO;
use App\Shared\Infrastructure\Repository\PublicIdRepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndicationNormalized>
 */
final class IndicationNormalizedRepository extends ServiceEntityRepository
{
    use PublicIdRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicationNormalized::class);
    }

    public function listQuery(IndicationQueryParametersDTO $queryParametersDTO): QueryBuilder
    {
        $qb = $this->createQueryBuilder('i')
            ->addSelect('(CASE WHEN i.updatedAt IS NOT NULL THEN i.updatedAt ELSE i.createdAt END) AS HIDDEN sortDate')
        ;

        if ('occurrence' === $queryParametersDTO->sortBy) {
            $qb->addSelect(sprintf(
                'ADD_SUBQUERIES((SELECT COUNT(o1.indicationNormalized) FROM %1$s o1 WHERE o1.indicationNormalized = i), (SELECT COUNT(o2.secondaryIndicationNormalized) FROM %1$s o2 WHERE o2.secondaryIndicationNormalized = i)) AS HIDDEN occurrenceCount',
                Allocation::class,
            ));
        } elseif ('rawCount' === $queryParametersDTO->sortBy) {
            $qb->addSelect('SIZE(i.children) AS HIDDEN rawCount');
        }

        // The id tie-breaker keeps rows with equal sort values on a stable page.
        $qb->orderBy(self::listSortField($queryParametersDTO->sortBy), $queryParametersDTO->orderBy)
            ->addOrderBy('i.id', $queryParametersDTO->orderBy);

        if (null !== $queryParametersDTO->search) {
            $qb->andWhere($qb->expr()->like('LOWER(i.name)', ':search'))
                ->setParameter('search', '%'.mb_strtolower($queryParametersDTO->search).'%')
            ;
        }

        return $qb;
    }

    /**
     * Maps a public sort key of the normalized indication list (see IndicationNormalizedListColumns::SORT_KEYS) to its DQL field.
     */
    public static function listSortField(string $sortBy): string
    {
        return match ($sortBy) {
            'name' => 'i.name',
            'rawCount' => 'rawCount',
            'occurrence' => 'occurrenceCount',
            'lastChange' => 'sortDate',
            default => 'i.code',
        };
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function getDatalist(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.id, i.name, i.code')
            ->orderBy('i.code', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static function (array $result): array {
            $label = $result['name'];
            if (!empty($result['code'])) {
                $label .= ' ('.$result['code'].')';
            }

            return ['id' => (int) $result['id'], 'label' => $label];
        }, $rows));
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

        return $result['name'].(empty($result['code']) ? '' : ' ('.$result['code'].')');
    }

    /**
     * Normalized indications that appear as a secondary indication on at least one allocation.
     *
     * @return list<IndicationNormalized>
     */
    public function findUsedAsSecondaryIndication(): array
    {
        /** @var list<IndicationNormalized> $result */
        $result = $this->createQueryBuilder('n')
            ->andWhere('n.code > 0')
            ->andWhere(
                'EXISTS (
                    SELECT 1 FROM '.Allocation::class.' a
                    WHERE a.secondaryIndicationNormalized = n
                )'
            )
            ->orderBy('n.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
