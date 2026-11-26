<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Query;

use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;

/**
 * Whether two-factor authentication is recommended for a user (#1357, ADR 040). For now only administrators: hospital
 * owners and users with an import grant are most of the members, so marking it for them recommended it to nearly
 * everyone (#1508). Who else should see the recommendation is evaluated later.
 */
final readonly class TwoFactorRecommendationQuery
{
    public function isRecommendedFor(User $user): bool
    {
        return \in_array(UserRole::ADMIN, $user->getRoles(), true);
    }
}
