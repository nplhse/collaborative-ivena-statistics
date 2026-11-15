<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Plugin\InpatientStay\Controller;

use App\Encounter\Contract\Access\EncounterAccessInterface;
use App\Tests\Fixtures\Plugin\InpatientStay\Entity\InpatientStay;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Target page of the Explore tile: the hospitals whose stays the user may view, then the stays of one
 * hospital. Access is checked per hospital through the contract (ADR 037).
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ListInpatientStaysController extends AbstractController
{
    public function __construct(
        private readonly EncounterAccessInterface $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/explore/inpatient-stays', name: 'app_explore_inpatient_stays', methods: ['GET'])]
    public function hospitals(): Response
    {
        return $this->render('@InpatientStayTest/hospitals.html.twig', ['hospitals' => $this->access->hospitalsWithViewCases()]);
    }

    #[Route('/explore/inpatient-stays/{hospitalId}', name: 'app_explore_inpatient_stays_hospital', requirements: ['hospitalId' => '\d+'], methods: ['GET'])]
    public function stays(int $hospitalId): Response
    {
        if (!$this->access->canViewCases($hospitalId)) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('@InpatientStayTest/stays.html.twig', [
            'stays' => $this->entityManager->getRepository(InpatientStay::class)->findBy(['hospitalId' => $hospitalId], ['admittedAt' => 'ASC', 'id' => 'ASC']),
        ]);
    }
}
