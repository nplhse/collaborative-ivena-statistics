<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

use App\Statistics\CaseFlow\Application\CaseFlowGeoKeyResolver;
use App\Statistics\CaseFlow\Application\CaseFlowPrivacySuppressor;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowCriteria;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowDispatchAreaMatch;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowOriginDistributionQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowOriginRow;

final readonly class GeographicSegmentCatalogService
{
    public function __construct(
        private CaseFlowOriginDistributionQuery $originDistributionQuery,
        private CaseFlowPrivacySuppressor $privacySuppressor,
        private CaseFlowGeoKeyResolver $geoKeyResolver,
        private GeographicSegmentCatalogFactory $catalogFactory,
    ) {
    }

    public function build(CaseFlowCriteria $criteria): GeographicSegmentCatalog
    {
        $match = $criteria->isDispatchAreaScope()
            ? CaseFlowDispatchAreaMatch::Catchment
            : CaseFlowDispatchAreaMatch::Related;
        $originRows = $this->originDistributionQuery->fetch(
            $criteria->period->from,
            $criteria->period->toExclusive,
            $criteria->scope,
            $criteria->originStateId(),
            $criteria->drawerFilter,
            $match,
        );
        $originTotal = array_sum(array_map(
            static fn (CaseFlowOriginRow $row): int => $row->caseCount,
            $originRows,
        ));
        $mapFeatures = $this->privacySuppressor->buildMapFeatures(
            $originRows,
            $originTotal,
            fn (int $id, string $name): string => $this->geoKeyResolver->resolve($id, $name),
        );

        return $this->catalogFactory->fromMapFeatures($mapFeatures, null, false);
    }
}
