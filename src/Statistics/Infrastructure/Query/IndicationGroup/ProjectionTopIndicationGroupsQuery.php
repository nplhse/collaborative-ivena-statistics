<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationGroup;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Statistics\Infrastructure\Entity\AllocationStatsProjection;
use App\Statistics\Infrastructure\Query\ProjectionFilterApplier;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProjectionTopIndicationGroupsQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProjectionFilterApplier $filterApplier,
    ) {
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return list<array{groupId: int, label: string, count: int}>
     */
    public function fetchTopGroupAggregates(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds, int $limit): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p')
            ->innerJoin(IndicationNormalized::class, 'inorm', 'WITH', 'inorm.id = p.indicationNormalizedId')
            ->innerJoin(IndicationGroup::class, 'g', 'WITH', 'g MEMBER OF inorm.groups')
            ->select('g.id AS groupId', 'g.name AS label', 'COUNT(p.id) AS cnt')
            ->groupBy('groupId', 'label')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults($limit);

        $this->filterApplier->applyCreatedAtRange($qb, 'p.createdAt', $from, $toExclusive);
        $this->filterApplier->applyHospitalScope($qb, 'p.hospitalId', $hospitalIds);

        /** @var list<array{groupId:int|string,label:string,cnt:numeric-string|int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'groupId' => (int) $row['groupId'],
                'label' => $row['label'],
                'count' => (int) $row['cnt'],
            ],
            $rows,
        );
    }
}
