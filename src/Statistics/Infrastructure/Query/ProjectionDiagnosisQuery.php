<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Infrastructure\Entity\AllocationStatsProjection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

final readonly class ProjectionDiagnosisQuery
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
     * @return list<array{label:string,count:int,indicationId:?int,publicId:?string}>
     */
    public function fetchTopDiagnosisAggregates(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
        int $limit,
        ?StatisticsDrawerFilter $drawerFilter = null,
        ?int $dispatchAreaId = null,
    ): array {
        $qb = $this->createBaseQb($from, $toExclusive, $hospitalIds, $drawerFilter, $dispatchAreaId)
            ->leftJoin(\App\Allocation\Domain\Entity\IndicationNormalized::class, 'inorm', 'WITH', 'inorm.id = p.indicationNormalizedId')
            ->select('inorm.id AS indicationId', 'inorm.publicId AS publicId', 'COALESCE(inorm.name, :unknown) AS label', 'COUNT(p.id) AS cnt')
            ->setParameter('unknown', 'Unknown')
            ->groupBy('indicationId', 'publicId', 'label')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults($limit);

        /** @var list<array{indicationId:int|string|null,publicId:Uuid|string|null,label:string,cnt:numeric-string|int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(
            static function (array $row): array {
                $indicationId = $row['indicationId'] ?? null;
                $publicId = $row['publicId'] ?? null;
                if ($publicId instanceof Uuid) {
                    $publicId = $publicId->toRfc4122();
                } elseif (!\is_string($publicId) || '' === $publicId) {
                    $publicId = null;
                }

                return [
                    'label' => $row['label'],
                    'count' => (int) $row['cnt'],
                    'indicationId' => null !== $indicationId && '' !== $indicationId ? (int) $indicationId : null,
                    'publicId' => $publicId,
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
}
