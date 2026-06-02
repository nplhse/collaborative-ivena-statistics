<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Repository;

use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Media>
 */
final class MediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Media::class);
    }

    public function findOneById(int $id): ?Media
    {
        return $this->find($id);
    }

    /**
     * @return list<Media>
     */
    public function findByType(MediaType $type): array
    {
        return $this->findBy(['type' => $type], ['createdAt' => 'DESC']);
    }
}
