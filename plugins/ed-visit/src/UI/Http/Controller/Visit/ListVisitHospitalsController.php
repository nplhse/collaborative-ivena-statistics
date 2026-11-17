<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\Http\Controller\Visit;

use App\User\Domain\Entity\User;
use Cois\Plugin\EdVisit\UI\VisitList\VisitListHospitals;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Entry of the visit list (#1178): the hospitals whose visits the user may see. With exactly one, it goes
 * straight to its list (ADR 028: one hospital at a time, no cross-hospital list).
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ListVisitHospitalsController extends AbstractController
{
    public function __construct(
        private readonly VisitListHospitals $hospitals,
    ) {
    }

    #[Route('/explore/ed-visits', name: 'app_explore_ed_visit_hospitals', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $hospitals = $this->hospitals->forUser($user);
        if (1 === \count($hospitals)) {
            return $this->redirectToRoute('app_explore_ed_visit_list', ['publicId' => $hospitals[0]['public_id']]);
        }

        return $this->render('@EdVisit/visit_list/hospitals.html.twig', ['hospitals' => $hospitals]);
    }
}
