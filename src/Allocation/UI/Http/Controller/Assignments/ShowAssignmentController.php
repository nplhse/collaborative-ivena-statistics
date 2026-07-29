<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Assignments;

use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Infrastructure\Query\Catalog\CatalogCoverageQuery;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowAssignmentController extends AbstractController
{
    public function __construct(
        private readonly CatalogCoverageQuery $coverageQuery,
        private readonly CatalogActionFactory $actionFactory,
    ) {
    }

    #[Route(
        '/explore/assignment/{publicId}',
        name: 'app_explore_assignment_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
        priority: 10,
    )]
    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] Assignment $assignment,
    ): Response {
        $id = $assignment->getId();
        if (null === $id) {
            throw $this->createNotFoundException();
        }

        $coverage = $this->coverageQuery->forDimension(CatalogDimensionKey::Assignment, $id);

        return $this->render('@Allocation/assignments/show.html.twig', [
            'assignment' => $assignment,
            'coverage' => $coverage,
            'actions' => $this->actionFactory->forAssignment($id),
        ]);
    }
}
