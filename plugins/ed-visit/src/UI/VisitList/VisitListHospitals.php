<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\VisitList;

use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Cois\Plugin\EdVisit\Application\Contract\VisitListReaderInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Hospitals whose visit list (#1178) the user may open: grant action VIEW_CASES (also set by REVIEW), or every
 * hospital with visits for ROLE_ADMIN, mirroring EncounterVoter::VIEW_CASES. Each list page still checks the
 * voter; this only fills the hospital choice.
 */
final readonly class VisitListHospitals
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EncounterAccessGrantRepositoryInterface $grants,
        private VisitListReaderInterface $reader,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @return list<array{id: int, public_id: string, name: string}>
     */
    public function forUser(User $user): array
    {
        if (!$this->authorizationChecker->isGranted(UserRole::ENCOUNTER_BETA)) {
            return [];
        }

        if ($this->authorizationChecker->isGranted(UserRole::ADMIN)) {
            return $this->reader->hospitals(null);
        }

        $ids = [];
        foreach ($this->grants->findForUser($user) as $grant) {
            if ($grant->allows(EncounterGrantAction::ViewCases)) {
                $ids[] = (int) $grant->getHospital()->getId();
            }
        }

        return $this->reader->hospitals($ids);
    }
}
