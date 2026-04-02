<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/statistics/distribution', name: 'app_stats_distribution', methods: ['GET'])]
final class DistributionPanelController extends AbstractController
{
    public function __invoke(): RedirectResponse
    {
        return $this->redirectToRoute('app_stats_distribution_urgency', [], 302);
    }
}
