<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Infrastructure\Entity\AllocationStatsProjection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class ProjectionTopEntityQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProjectionFilterApplier $filterApplier,
        private ProjectionDrawerFilterApplier $drawerFilterApplier,
    ) {
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return list<array{label:string,count:int,entityId:?int}>
     */
    public function fetchTopAggregates(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
        int $limit,
        string $projectionJoinProperty,
        string $entityFqcn,
        ?StatisticsDrawerFilter $drawerFilter = null,
        ?int $dispatchAreaId = null,
        bool $requireJoinedEntity = false,
    ): array {
        $qb = $this->createBaseQb($from, $toExclusive, $hospitalIds, $drawerFilter, $dispatchAreaId);
        if ($requireJoinedEntity) {
            $this->assertProjectionProperty($projectionJoinProperty);
            $qb->innerJoin($entityFqcn, 'ent', 'WITH', sprintf('ent.id = p.%s', $projectionJoinProperty))
                ->andWhere(sprintf('p.%s IS NOT NULL', $projectionJoinProperty));
        } else {
            $qb->leftJoin($entityFqcn, 'ent', 'WITH', sprintf('ent.id = p.%s', $projectionJoinProperty));
        }

        $qb->select('ent.id AS entityId', 'COALESCE(ent.name, :unknown) AS label', 'COUNT(p.id) AS cnt')
            ->setParameter('unknown', 'Unknown')
            ->groupBy('entityId', 'label')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults($limit);

        /** @var list<array{entityId:int|string|null,label:string,cnt:numeric-string|int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(
            static function (array $row): array {
                $entityId = $row['entityId'] ?? null;

                return [
                    'label' => $row['label'],
                    'count' => (int) $row['cnt'],
                    'entityId' => null !== $entityId && '' !== $entityId ? (int) $entityId : null,
                ];
            },
            $rows,
        );
    }

    /**
     * @param list<int>|null $hospitalIds
     */
    private function createBaseQb(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
        ?StatisticsDrawerFilter $drawerFilter = null,
        ?int $dispatchAreaId = null,
    ): QueryBuilder {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p');
        $this->filterApplier->applyCreatedAtRange($qb, 'p.createdAt', $from, $toExclusive);
        $this->filterApplier->applyHospitalScope($qb, 'p.hospitalId', $hospitalIds);
        $this->filterApplier->applyDispatchAreaScope($qb, 'p.dispatchAreaId', $dispatchAreaId);

        if ($drawerFilter instanceof StatisticsDrawerFilter && $drawerFilter->isActive()) {
            $this->drawerFilterApplier->apply($qb, $drawerFilter);
        }

        return $qb;
    }

    private function assertProjectionProperty(string $property): void
    {
        if (1 !== preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $property)) {
            throw new \InvalidArgumentException(sprintf('Invalid projection property "%s".', $property));
        }
    }
}
