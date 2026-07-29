<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Specialities;

use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Infrastructure\Query\Catalog\CatalogCoverageQuery;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowSpecialityController extends AbstractController
{
    public function __construct(
        private readonly CatalogCoverageQuery $coverageQuery,
        private readonly CatalogActionFactory $actionFactory,
    ) {
    }

    #[Route(
        '/explore/speciality/{publicId}',
        name: 'app_explore_speciality_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
        priority: 10,
    )]
    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] Speciality $speciality,
    ): Response {
        $id = $speciality->getId();
        if (null === $id) {
            throw $this->createNotFoundException();
        }

        $coverage = $this->coverageQuery->forDimension(CatalogDimensionKey::Speciality, $id);

        return $this->render('@Allocation/specialities/show.html.twig', [
            'speciality' => $speciality,
            'coverage' => $coverage,
            'actions' => $this->actionFactory->forSpeciality($id),
        ]);
    }
}
