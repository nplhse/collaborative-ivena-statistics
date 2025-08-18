<?php

namespace App\Controller\Data\Allocations;

use App\Entity\Allocation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ShowAllocationController extends AbstractController
{
    #[Route('/data/allocation/{id}', name: 'app_data_allocation_show')]
    public function index(Allocation $allocation): Response
    {
        return $this->render('data/allocations/show.html.twig', [
            'allocation' => $allocation,
        ]);
    }
}
