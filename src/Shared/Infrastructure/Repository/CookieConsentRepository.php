<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Repository;

use App\Shared\Domain\Entity\CookieConsent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CookieConsent>
 */
final class CookieConsentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CookieConsent::class);
    }

    public function findOneBySubjectId(string $subjectId): ?CookieConsent
    {
        return $this->findOneBy(['subjectId' => $subjectId]);
    }

    public function save(CookieConsent $consent): void
    {
        $this->getEntityManager()->persist($consent);
        $this->getEntityManager()->flush();
    }
}
