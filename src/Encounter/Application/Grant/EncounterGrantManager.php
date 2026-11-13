<?php

declare(strict_types=1);

namespace App\Encounter\Application\Grant;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Contract\HospitalMembershipReaderInterface;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates, changes and removes encounter access grants (ADR 028). Callers check
 * EncounterAttribute::MANAGE_GRANTS first; every change runs under an audit intent.
 */
final readonly class EncounterGrantManager
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EncounterAccessGrantRepositoryInterface $grantRepository,
        private HospitalMembershipReaderInterface $membershipReader,
        private AuditContext $auditContext,
    ) {
    }

    /**
     * @param list<EncounterGrantAction> $actions
     *
     * @throws EncounterGrantException when the user has no access to the hospital or already holds a grant
     */
    public function grant(Hospital $hospital, User $user, array $actions, ?User $actor): void
    {
        if (!$this->membershipReader->hasHospitalAccess($user, $hospital)) {
            throw new EncounterGrantException(EncounterGrantException::NOT_A_MEMBER);
        }

        if ($this->grantRepository->findForUserAndHospital($user, $hospital) instanceof EncounterAccessGrant) {
            throw new EncounterGrantException(EncounterGrantException::DUPLICATE);
        }

        $grant = new EncounterAccessGrant($hospital, $user, $actions, $actor);
        $this->entityManager->persist($grant);
        $this->flush('encounter.access_grant.created', $grant);
    }

    /**
     * @param list<EncounterGrantAction> $actions
     */
    public function change(EncounterAccessGrant $grant, array $actions, ?User $actor): void
    {
        $grant->changeActions($actions, $actor);
        $this->flush('encounter.access_grant.updated', $grant);
    }

    public function revoke(EncounterAccessGrant $grant): void
    {
        $this->entityManager->remove($grant);
        $this->flush('encounter.access_grant.deleted', $grant);
    }

    private function flush(string $intent, EncounterAccessGrant $grant): void
    {
        $this->auditContext->beginIntent($intent, [
            'hospital_id' => $grant->getHospital()->getId(),
            'user_id' => $grant->getUser()->getId(),
            'grant_id' => $grant->getId(),
            'actions' => array_map(static fn (EncounterGrantAction $a): string => $a->value, $grant->getActions()),
        ]);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }
    }
}
