<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Repository;

use Symfony\Component\Uid\Uuid;

trait PublicIdRepositoryTrait
{
    public function findOneByPublicId(Uuid|string $publicId): ?object
    {
        $resolved = (string) ($publicId instanceof Uuid ? $publicId : Uuid::fromString($publicId));

        return $this->createQueryBuilder('e')
            ->where('e.publicId = :publicId')
            ->setParameter('publicId', $resolved)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
