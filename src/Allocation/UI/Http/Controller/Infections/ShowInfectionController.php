<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Infections;

use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Infrastructure\Query\Catalog\CatalogCoverageQuery;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowInfectionController extends AbstractController
{
    public function __construct(
        private readonly CatalogCoverageQuery $coverageQuery,
        private readonly CatalogActionFactory $actionFactory,
    ) {
    }

    #[Route(
        '/explore/infection/{publicId}',
        name: 'app_explore_infection_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
        priority: 10,
    )]
    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] Infection $infection,
    ): Response {
        $id = $infection->getId();
        if (null === $id) {
            throw $this->createNotFoundException();
        }

        $coverage = $this->coverageQuery->forDimension(CatalogDimensionKey::Infection, $id);

        return $this->render('@Allocation/infections/show.html.twig', [
            'infection' => $infection,
            'coverage' => $coverage,
            'actions' => $this->actionFactory->forInfection($id),
        ]);
    }
}
