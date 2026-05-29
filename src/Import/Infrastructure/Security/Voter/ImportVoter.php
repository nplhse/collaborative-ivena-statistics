<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Security\Voter;

use App\Import\Application\Service\ImportListAccess;
use App\Import\Domain\Entity\Import;
use App\User\Domain\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Import>
 */
final class ImportVoter extends Voter
{
    public const string VIEW = 'VIEW';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly ImportListAccess $importListAccess,
    ) {
    }

    #[\Override]
    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, Import::class, true);
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Import && self::VIEW === $attribute;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $hospitalId = $subject->getHospital()?->getId();
        if (null === $hospitalId) {
            return false;
        }

        return $this->importListAccess->canAccessImportHospital($user, $hospitalId);
    }
}
