<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Repository;

use App\Content\Domain\Entity\MediaIntegrityCheck;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MediaIntegrityCheck>
 */
final class MediaIntegrityCheckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaIntegrityCheck::class);
    }

    public function latest(): ?MediaIntegrityCheck
    {
        return $this->findOneBy([], ['checkedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function save(MediaIntegrityCheck $check): void
    {
        $this->getEntityManager()->persist($check);
        $this->getEntityManager()->flush();
    }

    public function deleteCheckedBefore(\DateTimeImmutable $before): int
    {
        $deleted = $this->createQueryBuilder('c')
            ->delete()
            ->where('c.checkedAt < :before')
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }
}
