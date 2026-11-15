<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Assignment;

use App\Encounter\Application\Contract\AssignmentConflictReaderInterface;
use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Hospitals whose assignment conflicts (ADR 037) the user may review: grant action REVIEW, or every hospital
 * with open conflicts for administrators (they hold REVIEW implicitly, ADR 028). With exactly one hospital its
 * list opens directly.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ListAssignmentConflictHospitalsController extends AbstractController
{
    public function __construct(
        private readonly EncounterAccessGrantRepositoryInterface $grantRepository,
        private readonly AssignmentConflictReaderInterface $conflicts,
    ) {
    }

    #[Route('/tools/encounter-assignments', name: 'app_tools_encounter_assignments', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $hospitals = [];
        foreach ($this->grantRepository->findForUser($user) as $grant) {
            if ($grant->allows(EncounterGrantAction::Review)) {
                $hospitals[(int) $grant->getHospital()->getId()] = (string) $grant->getHospital()->getName();
            }
        }

        if ($this->isGranted('ROLE_ADMIN')) {
            foreach ($this->conflicts->hospitalsWithConflicts() as $hospital) {
                $hospitals[$hospital['id']] = $hospital['name'];
            }
        }

        asort($hospitals);
        if (1 === \count($hospitals)) {
            return $this->redirectToRoute('app_tools_encounter_assignments_hospital', ['id' => array_key_first($hospitals)]);
        }

        return $this->render('@Encounter/assignment/index.html.twig', ['hospitals' => $hospitals]);
    }
}
