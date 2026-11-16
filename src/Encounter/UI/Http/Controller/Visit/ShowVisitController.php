<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Visit;

use App\Encounter\Application\CaseCard\HospitalCourseFactory;
use App\Encounter\Application\Contract\HospitalCourseReaderInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Entity\EdVisit;
use App\Encounter\Domain\Entity\EncounterMatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Detail page of one ED visit (#1178), independent of IVENA: works with and without an allocation link.
 * Needs VIEW_CASES on the visit's hospital (ADR 028). Open proposals are review information and are read
 * only with REVIEW; nothing is decided here.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ShowVisitController extends AbstractController
{
    public function __construct(
        private readonly HospitalCourseReaderInterface $reader,
        private readonly HospitalCourseFactory $factory,
    ) {
    }

    #[Route('/explore/ed-visits/visit/{visitUid}', name: 'app_explore_ed_visit_show', requirements: ['visitUid' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $visitUid): Response
    {
        $encounter = $this->reader->visitByUid(Uuid::fromString($visitUid));
        if (!$encounter instanceof EdVisit) {
            throw new NotFoundHttpException();
        }

        $hospital = $encounter->getHospital();
        $this->denyAccessUnlessGranted(EncounterAttribute::VIEW_CASES, $hospital);

        $link = $this->reader->effectiveLinkOfVisit($encounter);
        $showProposals = !$link instanceof EncounterMatch && $this->isGranted(EncounterAttribute::REVIEW, $hospital);

        return $this->render('@Encounter/visit/show.html.twig', [
            'encounter' => $encounter,
            'hospital' => $hospital,
            'course' => $this->factory->forEncounter($encounter, $link),
            'proposals' => $showProposals ? $this->reader->openProposalsOfVisit($encounter) : [],
        ]);
    }
}
