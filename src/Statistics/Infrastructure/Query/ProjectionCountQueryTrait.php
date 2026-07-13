<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Infrastructure\Entity\AllocationStatsProjection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

trait ProjectionCountQueryTrait
{
    abstract protected function entityManager(): EntityManagerInterface;

    abstract protected function filterApplier(): ProjectionFilterApplier;

    abstract protected function drawerFilterApplier(): ProjectionDrawerFilterApplier;

    /**
     * @param list<int>|null $hospitalIds
     */
    private function createBaseCountQb(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
        ?StatisticsDrawerFilter $drawerFilter = null,
    ): QueryBuilder {
        $qb = $this->entityManager()->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p');
        $this->filterApplier()->applyCreatedAtRange($qb, 'p.createdAt', $from, $toExclusive);
        $this->filterApplier()->applyHospitalScope($qb, 'p.hospitalId', $hospitalIds);

        if ($drawerFilter instanceof StatisticsDrawerFilter && $drawerFilter->isActive()) {
            $this->drawerFilterApplier()->apply($qb, $drawerFilter);
        }

        return $qb;
    }
}
