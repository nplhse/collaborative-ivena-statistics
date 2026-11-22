<?php

declare(strict_types=1);

namespace App\Onboarding\Infrastructure\Repository;

use App\Onboarding\Domain\Entity\WhatsNewRelease;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WhatsNewRelease>
 */
final class WhatsNewReleaseRepository extends ServiceEntityRepository
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhatsNewRelease::class);
    }

    public function findOneByKey(string $releaseKey): ?WhatsNewRelease
    {
        return $this->find($releaseKey);
    }
}
