<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller;

use App\Allocation\Application\Explore\Overview\ExploreEntryRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/explore', name: 'app_explore_index', methods: ['GET'])]
final class ExploreController extends AbstractController
{
    public function __construct(
        private readonly ExploreEntryRegistry $entries,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->render('@Allocation/explore.html.twig', [
            'contributed_entries' => $this->entries->byGroup(),
        ]);
    }
}
