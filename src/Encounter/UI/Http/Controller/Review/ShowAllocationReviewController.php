<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Review;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\UI\Review\AllocationReviewFactory;
use App\Encounter\UI\Review\ReviewPeriodResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Review page of one allocation (#1054): its link or proposals, every candidate visit with the
 * contribution of each feature, competing claims and the swap proposal; the decision forms post to
 * DecideMatchController and ApplySwapController.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ShowAllocationReviewController extends AbstractController
{
    public function __construct(
        private readonly AllocationReviewFactory $factory,
        private readonly ReviewPeriodResolver $periods,
    ) {
    }

    #[Route('/encounter/review/{id}/allocations/{allocationId}', name: 'app_encounter_review_allocation', requirements: ['id' => '\d+', 'allocationId' => '\d+'], methods: ['GET'])]
    public function __invoke(Request $request, Hospital $hospital, int $allocationId): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);

        $page = $this->factory->create((int) $hospital->getId(), $allocationId);
        if (null === $page) {
            throw $this->createNotFoundException('Allocation not found in this hospital.');
        }

        $back = $request->query->all();
        unset($back['id'], $back['allocationId']);

        return $this->render('@Encounter/review/allocation.html.twig', [
            ...$page,
            'hospital' => $hospital,
            'period' => $this->periods->resolve($request, (int) $hospital->getId()),
            'back_query' => array_filter($back, \is_string(...)),
        ]);
    }
}
