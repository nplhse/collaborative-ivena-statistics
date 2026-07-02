<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Allocations;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Infrastructure\Repository\AllocationRepository;
use App\Allocation\Infrastructure\Security\Voter\AllocationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowAllocationController extends AbstractController
{
    public function __construct(
        private readonly AllocationRepository $allocationRepository,
    ) {
    }

    #[Route('/explore/allocation/{id}', name: 'app_explore_allocation_show', methods: ['GET'])]
    public function index(int $id): Response
    {
        $allocation = $this->allocationRepository->findOneForShow($id);
        if (!$allocation instanceof Allocation) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(AllocationVoter::VIEW, $allocation);

        return $this->render('@Allocation/allocations/show.html.twig', [
            'allocation' => $allocation,
        ]);
    }
}
