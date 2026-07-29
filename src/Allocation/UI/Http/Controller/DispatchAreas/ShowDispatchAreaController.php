<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\DispatchAreas;

use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Application\Explore\Catalog\CatalogOrientationMapFactory;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Infrastructure\Query\Catalog\CatalogCoverageQuery;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowDispatchAreaController extends AbstractController
{
    public function __construct(
        private readonly CatalogCoverageQuery $coverageQuery,
        private readonly CatalogActionFactory $actionFactory,
        private readonly CatalogOrientationMapFactory $orientationMapFactory,
    ) {
    }

    #[Route(
        '/explore/dispatch_area/{publicId}',
        name: 'app_explore_dispatch_area_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
        priority: 10,
    )]
    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] DispatchArea $dispatchArea,
    ): Response {
        $id = $dispatchArea->getId();
        if (null === $id) {
            throw $this->createNotFoundException();
        }

        $name = $dispatchArea->getName() ?? '';
        $coverage = $this->coverageQuery->forDimension(CatalogDimensionKey::DispatchArea, $id);

        return $this->render('@Allocation/dispatch_areas/show.html.twig', [
            'dispatchArea' => $dispatchArea,
            'coverage' => $coverage,
            'actions' => $this->actionFactory->forDispatchArea($id),
            'orientationMap' => $this->orientationMapFactory->forDispatchArea($name),
        ]);
    }
}
