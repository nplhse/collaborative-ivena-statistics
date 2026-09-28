<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Query;

use App\Import\Domain\Entity\ImportReject;
use App\Import\UI\Http\DTO\ImportRejectQueryParametersDTO;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** @psalm-suppress UnusedClass */
final readonly class ListImportRejectsQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function listQuery(ImportRejectQueryParametersDTO $query): QueryBuilder
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
            ->innerJoin('r.import', 'i')
            ->innerJoin('i.hospital', 'h');

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
            $qb->andWhere('LOWER(CAST_TEXT(r.messages)) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower(trim($query->search)).'%');
        }

        return $qb;
    }
}
