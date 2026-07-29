<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Indications;

use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
use App\Allocation\Application\Explore\Catalog\CatalogFallbackDescriptionFactory;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Infrastructure\Query\Catalog\CatalogCoverageQuery;
use App\Allocation\Infrastructure\Repository\IndicationGroupRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowIndicationGroupController extends AbstractController
{
    public function __construct(
        private readonly CatalogCoverageQuery $coverageQuery,
        private readonly CatalogFallbackDescriptionFactory $descriptionFactory,
        private readonly CatalogActionFactory $actionFactory,
        private readonly IndicationGroupRepository $indicationGroupRepository,
    ) {
    }

    #[Route(
        '/explore/indication_group/{publicId}',
        name: 'app_explore_indication_group_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
        priority: 10,
    )]
    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] IndicationGroup $indicationGroup,
    ): Response {
        $id = $indicationGroup->getId();
        if (null === $id) {
            throw $this->createNotFoundException();
        }

        $indicationIds = $this->indicationGroupRepository->getIndicationIds($id);
        $coverage = $this->coverageQuery->forIndicationIds($indicationIds);
        $name = $indicationGroup->getName() ?? '';
        $descriptionText = $indicationGroup->getDescription();
        $description = (null !== $descriptionText && '' !== trim($descriptionText))
            ? $descriptionText
            : $this->descriptionFactory->create($name, $coverage);

        return $this->render('@Allocation/indication_groups/show.html.twig', [
            'indicationGroup' => $indicationGroup,
            'coverage' => $coverage,
            'description' => $description,
            'actions' => $this->actionFactory->forIndicationGroup($id),
        ]);
    }
}
