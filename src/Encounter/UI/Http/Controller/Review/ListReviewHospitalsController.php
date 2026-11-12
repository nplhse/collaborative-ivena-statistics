<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Review;

use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Contract\MatchReviewReaderInterface;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Entry of the match review (#1054): the hospitals the user may review. With exactly one hospital the
 * review opens directly. Administrators see every hospital with encounter data (they hold REVIEW
 * implicitly, ADR 027).
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ListReviewHospitalsController extends AbstractController
{
    public function __construct(
        private readonly EncounterAccessGrantRepositoryInterface $grantRepository,
        private readonly MatchReviewReaderInterface $review,
    ) {
    }

    #[Route('/encounter/review', name: 'app_encounter_review_index', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $hospitals = [];
        foreach ($this->grantRepository->findForUser($user) as $grant) {
            if ($grant->allows(EncounterGrantAction::Review)) {
                $hospitals[(int) $grant->getHospital()->getId()] = (string) $grant->getHospital()->getName();
            }
        }

        if ($this->isGranted('ROLE_ADMIN')) {
            foreach ($this->review->hospitalsWithEncounters() as $hospital) {
                $hospitals[$hospital['id']] = $hospital['name'];
            }
        }

        asort($hospitals);
        if (1 === \count($hospitals)) {
            return $this->redirectToRoute('app_encounter_review_hospital', ['id' => array_key_first($hospitals)]);
        }

        return $this->render('@Encounter/review/index.html.twig', ['hospitals' => $hospitals]);
    }
}
