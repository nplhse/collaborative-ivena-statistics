<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

trait HospitalScopeQueryTrait
{
    /**
     * @param list<int>|null $hospitalIds
     */
    private function applyHospitalScope(QueryBuilder $qb, ?array $hospitalIds): void
    {
        if (null === $hospitalIds) {
            return;
        }

        $qb->andWhere('a.hospital IN (:hospitalIds)')
            ->setParameter('hospitalIds', $hospitalIds);
    }

    private function applyCreatedAtBefore(QueryBuilder $qb, ?\DateTimeInterface $toExclusive): void
    {
        if (!$toExclusive instanceof \DateTimeInterface) {
            return;
        }

        $qb->andWhere('a.createdAt < :toExclusive')
            ->setParameter('toExclusive', $toExclusive, Types::DATETIME_IMMUTABLE);
    }
}
