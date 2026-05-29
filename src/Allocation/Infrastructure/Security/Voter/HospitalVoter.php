<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Security\Voter;

use App\Allocation\Domain\Entity\Hospital;
use App\Import\Application\Service\ImportListAccess;
use App\User\Domain\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Hospital>
 */
final class HospitalVoter extends Voter
{
    public const string ACCESS = 'ACCESS';

    public const string EDIT = 'EDIT';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly ImportListAccess $importListAccess,
    ) {
    }

    #[\Override]
    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, Hospital::class, true);
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Hospital
            && \in_array($attribute, [self::ACCESS, self::EDIT], true);
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $hospitalId = $subject->getId();
        if (null === $hospitalId) {
            return false;
        }

        return match ($attribute) {
            self::ACCESS => $this->importListAccess->canAccessHospital($user, $hospitalId),
            self::EDIT => $subject->getOwner()?->getId() === $user->getId(),
            default => false,
        };
    }
}
