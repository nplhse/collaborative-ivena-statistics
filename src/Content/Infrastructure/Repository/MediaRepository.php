<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Repository;

use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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

    public function findOneByStorageKey(string $storageKey, ?MediaType $type = null): ?Media
    {
        $criteria = ['storageKey' => $storageKey];
        if ($type instanceof MediaType) {
            $criteria['type'] = $type;
        }

        return $this->findOneBy($criteria);
    }

    /**
     * Media item behind an old URL `/uploads/media/<filename>`: not yet moved (`filename`) or moved (`legacyFilename`).
     */
    public function findOneByLegacyFilename(string $filename, ?MediaType $type = null): ?Media
    {
        $query = $this->createQueryBuilder('m')
            ->where('m.filename = :filename OR m.legacyFilename = :filename')
            ->setParameter('filename', $filename, Types::STRING)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults(1);
        if ($type instanceof MediaType) {
            $query->andWhere('m.type = :type')->setParameter('type', $type->value, Types::STRING);
        }

        $media = $query->getQuery()->getOneOrNullResult();

        return $media instanceof Media ? $media : null;
    }
}
