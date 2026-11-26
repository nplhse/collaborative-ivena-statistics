<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Security\Voter;

use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * EncounterAttribute::VIEW_CASES_IN_ANY_HOSPITAL, without subject: ROLE_ENCOUNTER_BETA and the grant action
 * VIEW_CASES (also set by REVIEW) for at least one hospital, or ROLE_ADMIN (ADR 028). One query for users
 * that are not administrators.
 *
 * @extends Voter<string, null>
 *
 * @psalm-suppress UnusedClass Registered as a security voter; callers use EncounterAttribute.
 */
final class EncounterCasesAnywhereVoter extends Voter
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly EncounterAccessGrantRepositoryInterface $grantRepository,
    ) {
    }

    #[\Override]
    public function supportsAttribute(string $attribute): bool
    {
        return EncounterAttribute::VIEW_CASES_IN_ANY_HOSPITAL === $attribute;
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return null === $subject && $this->supportsAttribute($attribute);
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$this->accessDecisionManager->decide($token, [UserRole::ENCOUNTER_BETA])) {
            return false;
        }

        if ($this->accessDecisionManager->decide($token, [UserRole::ADMIN])) {
            return true;
        }

        return array_any($this->grantRepository->findForUser($user), fn (\App\Encounter\Domain\Entity\EncounterAccessGrant $grant): bool => $grant->allows(EncounterGrantAction::ViewCases));
    }
}
