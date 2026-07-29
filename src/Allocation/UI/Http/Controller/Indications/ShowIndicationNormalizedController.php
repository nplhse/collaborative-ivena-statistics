<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Indications;

use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Application\Explore\Catalog\CatalogFallbackDescriptionFactory;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Infrastructure\Query\Catalog\CatalogCoverageQuery;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowIndicationNormalizedController extends AbstractController
{
    public function __construct(
        private readonly CatalogCoverageQuery $coverageQuery,
        private readonly CatalogFallbackDescriptionFactory $descriptionFactory,
        private readonly CatalogActionFactory $actionFactory,
    ) {
    }

    #[Route(
        '/explore/indication/{publicId}',
        name: 'app_explore_indication_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
        priority: 10,
    )]
    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] IndicationNormalized $indication,
    ): Response {
        $id = $indication->getId();
        $code = $indication->getCode();
        if (null === $id || null === $code) {
            throw $this->createNotFoundException();
        }

        $coverage = $this->coverageQuery->forDimension(CatalogDimensionKey::Indication, $id);
        $name = $indication->getName() ?? '';
        $note = $indication->getNote();
        $description = (null !== $note && '' !== trim($note))
            ? $note
            : $this->descriptionFactory->create($name, $coverage);

        return $this->render('@Allocation/indications/show_normalized.html.twig', [
            'indication' => $indication,
            'coverage' => $coverage,
            'description' => $description,
            'actions' => $this->actionFactory->forIndication($id, $code),
        ]);
    }
}
