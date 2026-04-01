<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/statistics/distribution', name: 'app_stats_distribution', methods: ['GET'])]
final class DistributionPanelController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('@Statistics/distribution/index.html.twig');
    }
}
