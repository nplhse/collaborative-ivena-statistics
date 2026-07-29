<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\States;

use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Application\Explore\Catalog\CatalogOrientationMapFactory;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Infrastructure\Query\Catalog\CatalogCoverageQuery;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowStateController extends AbstractController
{
    public function __construct(
        private readonly CatalogCoverageQuery $coverageQuery,
        private readonly CatalogActionFactory $actionFactory,
        private readonly CatalogOrientationMapFactory $orientationMapFactory,
    ) {
    }

    #[Route(
        '/explore/state/{publicId}',
        name: 'app_explore_state_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
        priority: 10,
    )]
    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] State $state,
    ): Response {
        $id = $state->getId();
        if (null === $id) {
            throw $this->createNotFoundException();
        }

        $name = $state->getName() ?? '';
        $coverage = $this->coverageQuery->forDimension(CatalogDimensionKey::State, $id);

        return $this->render('@Allocation/states/show.html.twig', [
            'state' => $state,
            'coverage' => $coverage,
            'actions' => $this->actionFactory->forState($id),
            'orientationMap' => $this->orientationMapFactory->forState($name),
        ]);
    }
}
