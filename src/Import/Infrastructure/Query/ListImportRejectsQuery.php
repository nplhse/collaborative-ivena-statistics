<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Query;

use App\Allocation\Domain\Entity\Hospital;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportReject;
use App\Import\UI\Http\DTO\ImportRejectQueryParametersDTO;
use App\Shared\Infrastructure\Pagination\Paginator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;

final readonly class ListImportRejectsQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function getPaginator(ImportRejectQueryParametersDTO $query): Paginator
    {
        $qb = $this->entityManager->createQueryBuilder();

        $qb->select('
                r.id,
                r.createdAt,
                r.lineNumber,
                r.messages,
                r.row,
                i.id AS import_id,
                i.name AS import_name,
                h.id AS hospital_id,
                h.name AS hospital_name
            ')
            ->from(ImportReject::class, 'r')
            ->innerJoin(
                Import::class,
                'i',
                Join::WITH,
                'r.import = i.id'
            )
            ->innerJoin(
                Hospital::class,
                'h',
                Join::WITH,
                'i.hospital = h.id'
            );

        if (null !== $query->importId) {
            $qb->andWhere('i.id = :importId')
                ->setParameter('importId', $query->importId);
        }

        if (null !== $query->hospitalId) {
            $qb->andWhere('h.id = :hospitalId')
                ->setParameter('hospitalId', $query->hospitalId);
        }

        $field = match ($query->sortBy) {
            'importId' => 'i.id',
            'hospital' => 'h.name',
            default => 'r.createdAt',
        };

        $qb->orderBy($field, $query->orderBy);

        if (null !== $query->search && '' !== trim($query->search)) {
            $qb->andWhere("LOWER(FUNCTION('CAST', r.messages, 'text')) LIKE :search")
                ->setParameter('search', '%'.mb_strtolower(trim($query->search)).'%');
        }

        return (new Paginator($qb))->paginate($query->page, $query->limit);
    }
}
