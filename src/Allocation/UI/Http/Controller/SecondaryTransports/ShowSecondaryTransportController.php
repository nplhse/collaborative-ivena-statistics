<?php

namespace App\Allocation\UI\Http\Controller\SecondaryTransports;

use App\Allocation\Domain\Entity\SecondaryTransport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowSecondaryTransportController extends AbstractController
{
    #[Route('/explore/secondary_transport/{id}', name: 'app_explore_secondary_transport_show')]
    public function __invoke(SecondaryTransport $secondaryTransport): Response
    {
        return $this->render('@Allocation/secondary_transports/show.html.twig', [
            'secondaryTransport' => $secondaryTransport,
        ]);
    }
}
