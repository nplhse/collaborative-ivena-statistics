<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Occasions;

use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Application\Explore\Catalog\CatalogFallbackDescriptionFactory;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Infrastructure\Query\Catalog\CatalogCoverageQuery;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowOccasionController extends AbstractController
{
    public function __construct(
        private readonly CatalogCoverageQuery $coverageQuery,
        private readonly CatalogFallbackDescriptionFactory $descriptionFactory,
        private readonly CatalogActionFactory $actionFactory,
    ) {
    }

    #[Route(
        '/explore/occasion/{publicId}',
        name: 'app_explore_occasion_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
        priority: 10,
    )]
    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] Occasion $occasion,
    ): Response {
        $id = $occasion->getId();
        if (null === $id) {
            throw $this->createNotFoundException();
        }

        $name = $occasion->getName() ?? '';
        $coverage = $this->coverageQuery->forDimension(CatalogDimensionKey::Occasion, $id);

        return $this->render('@Allocation/occasions/show.html.twig', [
            'occasion' => $occasion,
            'coverage' => $coverage,
            'description' => $this->descriptionFactory->create($name, $coverage),
            'actions' => $this->actionFactory->forOccasion($id),
        ]);
    }
}
