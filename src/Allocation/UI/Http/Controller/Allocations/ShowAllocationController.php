<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Allocations;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Infrastructure\Security\Voter\AllocationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ShowAllocationController extends AbstractController
{
    #[Route('/explore/allocation/{id}', name: 'app_explore_allocation_show', methods: ['GET'])]
    #[IsGranted(AllocationVoter::VIEW, subject: 'allocation')]
    public function index(Allocation $allocation): Response
    {
        return $this->render('@Allocation/allocations/show.html.twig', [
            'allocation' => $allocation,
        ]);
    }
}
