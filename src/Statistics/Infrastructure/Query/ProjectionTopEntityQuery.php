<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Infrastructure\Entity\AllocationStatsProjection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class ProjectionTopEntityQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProjectionFilterApplier $filterApplier,
    ) {
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return list<array{label:string,count:int}>
     */
    public function fetchTopAggregates(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
        int $limit,
        string $projectionJoinProperty,
        string $entityFqcn,
    ): array {
        $qb = $this->createBaseQb($from, $toExclusive, $hospitalIds)
            ->leftJoin($entityFqcn, 'ent', 'WITH', sprintf('ent.id = p.%s', $projectionJoinProperty))
            ->select('COALESCE(ent.name, :unknown) AS label', 'COUNT(p.id) AS cnt')
            ->setParameter('unknown', 'Unknown')
            ->groupBy('label')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults($limit);

        /** @var list<array{label:string,cnt:numeric-string|int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): array => ['label' => $row['label'], 'count' => (int) $row['cnt']],
            $rows,
        );
    }

    /**
     * @param list<int>|null $hospitalIds
     */
    private function createBaseQb(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p');
        $this->filterApplier->applyCreatedAtRange($qb, 'p.createdAt', $from, $toExclusive);
        $this->filterApplier->applyHospitalScope($qb, 'p.hospitalId', $hospitalIds);

        return $qb;
    }
}
