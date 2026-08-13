<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Query;

use App\Allocation\Domain\Entity\Hospital;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UserCreatedAtBackfillHospitalQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Owned hospitals only. Access-grant associations are ignored because those
     * users may have been created later than the hospital's first import.
     *
     * @return array<int, list<array{id: int, name: string}>>
     */
    public function ownedHospitalsByUserId(): array
    {
        /** @var list<array{userId: int|string, id: int|string, name: string}> $owned */
        $owned = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(h.owner) AS userId', 'h.id AS id', 'h.name AS name')
            ->from(Hospital::class, 'h')
            ->andWhere('IDENTITY(h.owner) IS NOT NULL')
            ->getQuery()
            ->getArrayResult();

        $byUser = [];
        foreach ($owned as $row) {
            $userId = (int) $row['userId'];
            $byUser[$userId][] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
            ];
        }

        return $byUser;
    }
}
