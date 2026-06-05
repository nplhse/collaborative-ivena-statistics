<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AnalyticsExplorerController extends AbstractController
{
    #[Route('/statistics/analytics', name: 'app_stats_analytics', methods: ['GET'])]
    public function __invoke(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('app_stats_analytics_library', $request->query->all());
    }
}
