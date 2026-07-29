<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\SecondaryTransports;

use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Application\Explore\Catalog\CatalogFallbackDescriptionFactory;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Infrastructure\Query\Catalog\CatalogCoverageQuery;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowSecondaryTransportController extends AbstractController
{
    public function __construct(
        private readonly CatalogCoverageQuery $coverageQuery,
        private readonly CatalogFallbackDescriptionFactory $descriptionFactory,
        private readonly CatalogActionFactory $actionFactory,
    ) {
    }

    #[Route(
        '/explore/secondary_transport/{publicId}',
        name: 'app_explore_secondary_transport_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] SecondaryTransport $secondaryTransport,
    ): Response {
        $id = $secondaryTransport->getId();
        if (null === $id) {
            throw $this->createNotFoundException();
        }

        $coverage = $this->coverageQuery->forDimension(CatalogDimensionKey::SecondaryTransport, $id);
        $name = $secondaryTransport->getName() ?? '';

        return $this->render('@Allocation/secondary_transports/show.html.twig', [
            'secondaryTransport' => $secondaryTransport,
            'coverage' => $coverage,
            'description' => $this->descriptionFactory->create($name, $coverage),
            'actions' => $this->actionFactory->forSecondaryTransport($id),
        ]);
    }
}
