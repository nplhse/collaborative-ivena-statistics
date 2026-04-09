<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/statistics/', name: 'app_stats_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@Statistics/dashboard/index.html.twig');
    }
}
