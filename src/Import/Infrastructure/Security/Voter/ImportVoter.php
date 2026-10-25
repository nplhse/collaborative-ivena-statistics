<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Security\Voter;

use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Import\Application\Service\ImportCreatorPermissionCheck;
use App\Import\Application\Service\ImportFileStorage;
use App\Import\Application\Service\ImportListAccess;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Import>
 */
final class ImportVoter extends Voter
{
    public const string VIEW = 'VIEW';

    public const string DELETE = 'DELETE';

    public const string DOWNLOAD_SOURCE = 'DOWNLOAD_SOURCE';

    public const string REPROCESS = 'REPROCESS';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly ImportListAccess $importListAccess,
        private readonly HospitalPermissionAccess $hospitalPermissionAccess,
        private readonly ImportCreatorPermissionCheck $creatorPermissionCheck,
        private readonly ImportFileStorage $fileStorage,
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
        return $subject instanceof Import && \in_array($attribute, [self::VIEW, self::DELETE, self::DOWNLOAD_SOURCE, self::REPROCESS], true);
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($this->closureBetaDenied($subject, $user)) {
            return false;
        }

        $hospitalId = $subject->getHospital()?->getId();

        if (self::VIEW === $attribute) {
            if (null === $hospitalId) {
                return false;
            }

            return $this->importListAccess->canAccessImportHospital($user, $hospitalId);
        }

        if (self::DOWNLOAD_SOURCE === $attribute) {
            if (!\in_array(UserRole::ADMIN, $user->getRoles(), true)) {
                return false;
            }

            if (null === $hospitalId) {
                return false;
            }

            return $this->importListAccess->canAccessImportHospital($user, $hospitalId);
        }

        if (self::REPROCESS === $attribute) {
            // The run is processed as the creator and reads the stored file, so both must still be usable.
            return $this->canManage($subject, $user, $hospitalId)
                && !$this->creatorPermissionCheck->failureFor($subject) instanceof \App\Import\Domain\Enum\ImportRunReasonCode
                && $this->fileStorage->exists($subject->getFilePath());
        }

        return $this->canManage($subject, $user, $hospitalId);
    }

    /**
     * Delete and reprocess: admins, the hospital owner, and the creator while they can access the hospital.
     */
    private function canManage(Import $subject, User $user, ?int $hospitalId): bool
    {
        if (\in_array(UserRole::ADMIN, $user->getRoles(), true)) {
            return true;
        }

        $hospital = $subject->getHospital();
        if (!$hospital instanceof \App\Allocation\Domain\Entity\Hospital || null === $hospitalId) {
            return false;
        }

        if ($this->hospitalPermissionAccess->isOwner($user, $hospital)) {
            return true;
        }

        if (!$this->importListAccess->canAccessImportHospital($user, $hospitalId)) {
            return false;
        }

        $createdBy = $subject->getCreatedBy();

        return $createdBy instanceof User && $createdBy->getId() === $user->getId();
    }

    private function closureBetaDenied(Import $import, User $user): bool
    {
        if (ImportType::CLOSURE !== $import->getType()) {
            return false;
        }

        return !\in_array(UserRole::CLOSURE_BETA, $user->getRoles(), true);
    }
}
