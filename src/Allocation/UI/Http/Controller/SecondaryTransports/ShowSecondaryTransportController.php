<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\SecondaryTransports;

use App\Allocation\Domain\Entity\SecondaryTransport;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowSecondaryTransportController extends AbstractController
{
    #[Route(
        '/explore/secondary_transport/{publicId}',
        name: 'app_explore_secondary_transport_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] SecondaryTransport $secondaryTransport,
    ): Response {
        return $this->render('@Allocation/secondary_transports/show.html.twig', [
            'secondaryTransport' => $secondaryTransport,
        ]);
    }
}
