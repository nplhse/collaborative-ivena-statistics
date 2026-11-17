<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Assignment;

use App\Allocation\Domain\Entity\Allocation;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Contract\Assignment\EncounterAllocationAssignmentsInterface;
use App\Encounter\Contract\Assignment\EncounterHospitalLockInterface;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Removes the effective assignment of an IVENA allocation to its encounter by hand (#1300), for a reviewer of the
 * hospital (`REVIEW`). Offered in the case file when the encounter has no ED visit any more; with an ED visit the
 * ED match review decides. The encounter and its records stay; a later record or matcher run can assign it again.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class UnassignEncounterController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EncounterHospitalLockInterface $lock,
        private readonly EncounterAllocationAssignmentsInterface $assignments,
    ) {
    }

    #[Route('/explore/allocation/{publicId}/encounter/unassign', name: 'app_explore_allocation_encounter_unassign', requirements: ['publicId' => Requirement::UUID], methods: ['POST'])]
    public function __invoke(Request $request, #[MapEntity(mapping: ['publicId' => 'publicId'])] Allocation $allocation): RedirectResponse
    {
        $hospital = $allocation->getHospital();
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);
        $back = $this->redirectToRoute('app_explore_allocation_show', ['publicId' => $allocation->getPublicId()]);

        $allocationId = (int) $allocation->getId();
        if (!$this->isCsrfTokenValid(self::tokenId($allocationId), $request->request->getString('_token'))) {
            $this->addFlash('danger', new TranslatableMessage('encounter.course.unassign.invalid_token', domain: 'encounter'));

            return $back;
        }

        $user = $this->getUser();
        $removed = $this->connection->transactional(function () use ($hospital, $allocationId, $user): bool {
            $this->lock->lock((int) $hospital?->getId());
            $encounterId = $this->assignments->encounterOfAllocation($allocationId);
            $hasVisit = null !== $encounterId && false !== $this->connection->fetchOne('SELECT 1 FROM ed_visit WHERE encounter_id = ?', [$encounterId]);
            if (null === $encounterId || $hasVisit) {
                return false;
            }

            $this->assignments->apply([$encounterId => null], $user instanceof User ? $user->getId() : null);

            return true;
        });

        $this->addFlash($removed ? 'success' : 'warning', new TranslatableMessage($removed ? 'encounter.course.unassign.done' : 'encounter.course.unassign.not_possible', domain: 'encounter'));

        return $back;
    }

    public static function tokenId(int $allocationId): string
    {
        return 'encounter_unassign_'.$allocationId;
    }
}
