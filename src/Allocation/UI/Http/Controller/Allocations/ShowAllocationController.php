<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Allocations;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Infrastructure\Security\Voter\AllocationVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowAllocationController extends AbstractController
{
    #[Route(
        '/explore/allocation/{publicId}',
        name: 'app_explore_allocation_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function index(
        #[MapEntity(expr: 'repository.findOneForShowByPublicId(publicId)')] Allocation $allocation,
    ): Response {
        $this->denyAccessUnlessGranted(AllocationVoter::VIEW, $allocation);

        return $this->render('@Allocation/allocations/show.html.twig', [
            'allocation' => $allocation,
        ]);
    }
}
