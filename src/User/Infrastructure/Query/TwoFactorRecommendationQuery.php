<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Query;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Whether two-factor authentication is recommended for a user (#1357, ADR 040): owners of a hospital (they manage its
 * access grants) and users with an import grant. One query.
 */
final readonly class TwoFactorRecommendationQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function isRecommendedFor(User $user): bool
    {
        if (null === $user->getId()) {
            return false;
        }

        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM hospital WHERE owner_id = :user)
                 OR EXISTS (SELECT 1 FROM hospital_access_grant WHERE user_id = :user AND (permissions & :import) <> 0)',
            ['user' => $user->getId(), 'import' => HospitalPermission::Import->value],
        );
    }
}
