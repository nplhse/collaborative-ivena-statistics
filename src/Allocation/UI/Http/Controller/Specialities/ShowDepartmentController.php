<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Specialities;

use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Application\Explore\Catalog\CatalogFallbackDescriptionFactory;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Infrastructure\Query\Catalog\CatalogCoverageQuery;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowDepartmentController extends AbstractController
{
    public function __construct(
        private readonly CatalogCoverageQuery $coverageQuery,
        private readonly CatalogFallbackDescriptionFactory $descriptionFactory,
        private readonly CatalogActionFactory $actionFactory,
    ) {
    }

    #[Route(
        '/explore/department/{publicId}',
        name: 'app_explore_department_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
        priority: 10,
    )]
    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] Department $department,
    ): Response {
        $id = $department->getId();
        if (null === $id) {
            throw $this->createNotFoundException();
        }

        $name = $department->getName() ?? '';
        $coverage = $this->coverageQuery->forDimension(CatalogDimensionKey::Department, $id);

        return $this->render('@Allocation/departments/show.html.twig', [
            'department' => $department,
            'coverage' => $coverage,
            'description' => $this->descriptionFactory->create($name, $coverage),
            'actions' => $this->actionFactory->forDepartment($id),
        ]);
    }
}
