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

    /**
     * Ids of media items whose file is still in the old upload directory (ADR 039), oldest first.
     *
     * @return list<int>
     */
    public function findIdsNotMoved(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.storageKey IS NULL')
            ->andWhere('m.filename IS NOT NULL')
            ->andWhere("m.filename <> ''")
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }

    /**
     * Every old file name a media item refers to, moved or not.
     *
     * @return list<string>
     */
    public function findLegacyFilenames(): array
    {
        /** @var list<string|null> $names */
        $names = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT filename FROM media WHERE filename IS NOT NULL UNION SELECT legacy_filename FROM media WHERE legacy_filename IS NOT NULL',
        );

        return array_values(array_filter($names, static fn (?string $name): bool => null !== $name && '' !== $name));
    }
}
