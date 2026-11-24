<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Repository;

use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\TermsAcceptanceKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TermsAcceptance>
 */
final class TermsAcceptanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TermsAcceptance::class);
    }

    /** The most recent acceptance of this kind, or null when the account never accepted. */
    public function latestFor(User $user, TermsAcceptanceKind $kind = TermsAcceptanceKind::Terms): ?TermsAcceptance
    {
        return $this->findOneBy(['user' => $user, 'kind' => $kind], ['acceptedAt' => 'DESC', 'id' => 'DESC']);
    }

    /** Whether the account ever accepted this version. */
    public function hasAccepted(User $user, string $version, TermsAcceptanceKind $kind = TermsAcceptanceKind::Terms): bool
    {
        return null !== $this->findOneBy(['user' => $user, 'kind' => $kind, 'termsVersion' => $version]);
    }
}
