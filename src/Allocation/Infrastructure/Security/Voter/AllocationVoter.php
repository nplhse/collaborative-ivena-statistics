<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Security\Voter;

use App\Allocation\Domain\Entity\Allocation;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Explore allocation detail: any authenticated user with ROLE_USER may VIEW (no per-hospital restriction).
 *
 * @extends Voter<string, Allocation>
 */
final class AllocationVoter extends Voter
{
    public const string VIEW = 'VIEW';

    #[\Override]
    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, Allocation::class, true);
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Allocation && self::VIEW === $attribute;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return \in_array(UserRole::USER, $user->getRoles(), true);
    }
}
