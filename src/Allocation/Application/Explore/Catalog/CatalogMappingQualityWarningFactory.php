<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

use App\Allocation\Application\DTO\CatalogQualityWarning;
use App\Allocation\Infrastructure\Query\IndicationRawReviewHealthCheckQuery;
use App\Allocation\Infrastructure\Query\IndicationRawReviewHealthCheckSeverity;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Surfaces inexpensive indication-raw mapping integrity warnings for catalog/worklist UI.
 */
final readonly class CatalogMappingQualityWarningFactory
{
    public function __construct(
        private IndicationRawReviewHealthCheckQuery $healthCheckQuery,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return list<CatalogQualityWarning>
     */
    public function create(): array
    {
        $warnings = [];
        foreach ($this->healthCheckQuery->runMappingIntegrityChecks() as $result) {
            if (0 === $result->count || IndicationRawReviewHealthCheckSeverity::Info === $result->severity) {
                continue;
            }

            $warnings[] = new CatalogQualityWarning(
                id: $result->id,
                labelKey: 'catalog.quality.check.'.$result->id,
                count: $result->count,
                severity: $result->severity,
                hintKey: '' !== $result->hint ? 'catalog.quality.hint.'.$result->id : '',
                actionUrl: $this->urlGenerator->generate('app_explore_indication_raw_review_worklist'),
            );
        }

        return $warnings;
    }
}
