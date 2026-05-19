<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Query;

use App\Import\Application\Service\ImportListAccess;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\UI\Http\DTO\ListImportQueryParametersDTO;
use App\Shared\Infrastructure\Pagination\Paginator;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ListImportsQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImportListAccess $importListAccess,
    ) {
    }

    public function getPaginator(User $user, ListImportQueryParametersDTO $query): Paginator
    {
        $accessibleHospitalIds = $this->importListAccess->resolveAccessibleHospitalIds($user);

        $qb = $this->entityManager->createQueryBuilder()
            ->select('i', 'h', 'owner', 'createdBy', 'updatedBy')
            ->addSelect('(CASE WHEN i.updatedAt IS NOT NULL THEN i.updatedAt ELSE i.createdAt END) AS HIDDEN sortDate')
            ->from(Import::class, 'i')
            ->innerJoin('i.hospital', 'h')
            ->leftJoin('h.owner', 'owner')
            ->leftJoin('i.createdBy', 'createdBy')
            ->leftJoin('i.updatedBy', 'updatedBy');

        if ([] === $accessibleHospitalIds) {
            $qb->andWhere('1 = 0');
        } else {
            $qb->andWhere('h.id IN (:accessibleHospitalIds)')
                ->setParameter('accessibleHospitalIds', $accessibleHospitalIds);
        }

        $hospitalId = $this->importListAccess->sanitizeHospitalId($user, $query->hospitalId);
        if (null !== $hospitalId) {
            $qb->andWhere('h.id = :hospitalId')
                ->setParameter('hospitalId', $hospitalId);
        }

        $ownerId = $this->importListAccess->sanitizeOwnerId($user, $query->ownerId);
        if (null !== $ownerId) {
            $qb->andWhere('owner.id = :ownerId')
                ->setParameter('ownerId', $ownerId);
        }

        if (null !== $query->status && '' !== $query->status) {
            $qb->andWhere('i.status = :status')
                ->setParameter('status', ImportStatus::from($query->status));
        }

        if (null !== $query->createdFrom && '' !== $query->createdFrom) {
            $from = \DateTimeImmutable::createFromFormat('Y-m-d', $query->createdFrom);
            if (false !== $from) {
                $qb->andWhere('i.createdAt >= :createdFrom')
                    ->setParameter('createdFrom', $from->setTime(0, 0, 0), Types::DATETIME_IMMUTABLE);
            }
        }

        if (null !== $query->createdUntil && '' !== $query->createdUntil) {
            $until = \DateTimeImmutable::createFromFormat('Y-m-d', $query->createdUntil);
            if (false !== $until) {
                $untilExclusive = $until->modify('+1 day')->setTime(0, 0, 0);
                $qb->andWhere('i.createdAt < :createdUntilExclusive')
                    ->setParameter('createdUntilExclusive', $untilExclusive, Types::DATETIME_IMMUTABLE);
            }
        }

        if (null !== $query->search && '' !== trim($query->search)) {
            $search = '%'.trim($query->search).'%';
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like('i.name', ':search'),
                $qb->expr()->like('h.name', ':search'),
            ))->setParameter('search', $search);
        }

        $sortField = match ($query->sortBy) {
            'hospital' => 'h.name',
            'lastChange' => 'sortDate',
            'status' => 'i.status',
            'createdAt' => 'i.createdAt',
            default => 'i.'.$query->sortBy,
        };

        $qb->orderBy($sortField, $query->orderBy);

        return new Paginator($qb)->paginate($query->page, $query->limit);
    }
}
