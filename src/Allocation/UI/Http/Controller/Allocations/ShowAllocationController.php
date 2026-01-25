<?php

namespace App\Allocation\UI\Http\Controller\Allocations;

use App\Allocation\Domain\Entity\Allocation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowAllocationController extends AbstractController
{
    #[Route('/explore/allocation/{id}', name: 'app_explore_allocation_show')]
    public function index(Allocation $allocation): Response
    {
        return $this->render('@Allocation/allocations/show.html.twig', [
            'allocation' => $allocation,
        ]);
    }
}
