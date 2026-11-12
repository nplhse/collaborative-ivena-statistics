<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Security\Voter;

use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Access to encounter data of one hospital (ADR 027).
 *
 * Every attribute needs ROLE_ENCOUNTER_BETA. ROLE_ADMIN counts as holding every grant action on
 * every hospital without a stored grant. Hospital ownership and hospital access grants never imply an
 * encounter grant. The key change is for the hospital owner or ROLE_ADMIN; no grant action allows it.
 * Impersonation needs no special case: the token carries the impersonated user's roles and grants.
 *
 * @extends Voter<string, Hospital>
 *
 * @psalm-suppress UnusedClass Registered as a security voter; callers use EncounterAttribute.
 */
final class EncounterVoter extends Voter
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly EncounterAccessGrantRepositoryInterface $grantRepository,
        private readonly HospitalPermissionAccess $hospitalPermissionAccess,
    ) {
    }

    #[\Override]
    public function supportsAttribute(string $attribute): bool
    {
        return \in_array($attribute, EncounterAttribute::ALL, true);
    }

    #[\Override]
    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, Hospital::class, true);
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Hospital && $this->supportsAttribute($attribute);
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || null === $subject->getId()) {
            return false;
        }

        if (!$this->accessDecisionManager->decide($token, [UserRole::ENCOUNTER_BETA])) {
            return false;
        }

        return match ($attribute) {
            EncounterAttribute::CHANGE_KEY,
            EncounterAttribute::MANAGE_GRANTS => $this->isOwner($user, $subject) || $this->isAdmin($token),
            EncounterAttribute::UPLOAD,
            EncounterAttribute::VIEW_SOURCE_FILE,
            EncounterAttribute::DOWNLOAD_SOURCE_FILE,
            EncounterAttribute::REPROCESS_SOURCE_FILE => $this->grantAllows($token, $user, $subject, EncounterGrantAction::Upload),
            EncounterAttribute::REVIEW => $this->grantAllows($token, $user, $subject, EncounterGrantAction::Review),
            EncounterAttribute::VIEW_CASES => $this->grantAllows($token, $user, $subject, EncounterGrantAction::ViewCases),
            EncounterAttribute::VIEW_AGGREGATES => $this->hasAnyGrant($token, $user, $subject)
                && $this->hospitalPermissionAccess->hasPermission($user, (int) $subject->getId(), HospitalPermission::Statistics),
            default => false,
        };
    }

    /**
     * Administrators hold every grant action implicitly (ADR 027).
     */
    private function grantAllows(TokenInterface $token, User $user, Hospital $hospital, EncounterGrantAction $action): bool
    {
        if ($this->isAdmin($token)) {
            return true;
        }

        $grant = $this->grantRepository->findForUserAndHospital($user, $hospital);

        return $grant instanceof EncounterAccessGrant && $grant->allows($action);
    }

    private function hasAnyGrant(TokenInterface $token, User $user, Hospital $hospital): bool
    {
        return $this->isAdmin($token)
            || $this->grantRepository->findForUserAndHospital($user, $hospital) instanceof EncounterAccessGrant;
    }

    private function isOwner(User $user, Hospital $hospital): bool
    {
        $owner = $hospital->getOwner();

        return $owner instanceof User && null !== $user->getId() && $owner->getId() === $user->getId();
    }

    private function isAdmin(TokenInterface $token): bool
    {
        return $this->accessDecisionManager->decide($token, [UserRole::ADMIN]);
    }
}
