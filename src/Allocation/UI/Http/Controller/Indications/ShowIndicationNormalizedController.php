<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Indications;

use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
use App\Allocation\Application\Explore\Catalog\CatalogDefinitionChangeFactory;
use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Infrastructure\Query\Catalog\CatalogCoverageQuery;
use App\Allocation\Infrastructure\Query\Catalog\CatalogIndicationNormalizationQuery;
use App\Allocation\Infrastructure\Security\Voter\IndicationRawReviewVoter;
use App\Analytics\Application\UsageEvents\UsageAnalytics;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Domain\UsageEventName;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowIndicationNormalizedController extends AbstractController
{
    public function __construct(
        private readonly CatalogCoverageQuery $coverageQuery,
        private readonly CatalogActionFactory $actionFactory,
        private readonly CatalogIndicationNormalizationQuery $normalizationQuery,
        private readonly CatalogDefinitionChangeFactory $definitionChangeFactory,
        private readonly UsageAnalytics $usageAnalytics,
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

        $canViewNormalization = $this->isGranted(IndicationRawReviewVoter::EDIT_MATCH);
        $normalization = $canViewNormalization
            ? $this->normalizationQuery->forTarget($id)
            : null;

        $this->usageAnalytics->record(
            UsageEventName::EXPLORE_INDICATION_OPENED,
            FeatureArea::Explore,
            ['entity' => 'indication'],
        );

        return $this->render('@Allocation/indications/show_normalized.html.twig', [
            'indication' => $indication,
            'coverage' => $coverage,
            'actions' => $this->actionFactory->forIndication($id, $code, $canViewNormalization),
            'normalization' => $normalization,
            'qualityWarnings' => $normalization instanceof \App\Allocation\Application\DTO\CatalogNormalizationSummary ? $normalization->warnings : [],
            'definitionChanges' => $this->definitionChangeFactory->forIndicationNormalized($indication),
            'canViewNormalization' => $canViewNormalization,
        ]);
    }
}
