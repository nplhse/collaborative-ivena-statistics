<?php

namespace App\Allocation\UI\Http\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/explore', name: 'app_explore_index')]
final class ExploreController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('@Allocation/explore.html.twig', []);
    }
}
