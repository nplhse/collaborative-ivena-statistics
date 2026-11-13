<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Review;

use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Contract\MatchReviewReaderInterface;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Entry of the match review (#1054) and of the validation (#1128 Tools card): the hospitals the user may
 * review. `?mode=validation` links each hospital to its validation samples instead of the review. With
 * exactly one hospital the chosen page opens directly. Administrators see every hospital with encounter
 * data (they hold REVIEW implicitly, ADR 028).
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ListReviewHospitalsController extends AbstractController
{
    public const string MODE_VALIDATION = 'validation';

    public function __construct(
        private readonly EncounterAccessGrantRepositoryInterface $grantRepository,
        private readonly MatchReviewReaderInterface $review,
    ) {
    }

    #[Route('/tools/encounter-review', name: 'app_tools_encounter_review', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $user, Request $request): Response
    {
        $validation = self::MODE_VALIDATION === $request->query->getString('mode');
        $targetRoute = $validation ? 'app_tools_encounter_validation' : 'app_tools_encounter_review_hospital';

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
            return $this->redirectToRoute($targetRoute, ['id' => array_key_first($hospitals)]);
        }

        return $this->render('@Encounter/review/index.html.twig', [
            'hospitals' => $hospitals,
            'target_route' => $targetRoute,
            'validation' => $validation,
        ]);
    }
}
