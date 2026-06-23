<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;

final readonly class AnalysisAxisUpgradeMapper
{
    /**
     * Upgrade v2 dimension + grain to explicit v3 rows/columns.
     * Preserves legacy orientation for "over time" views (rows=time, columns=breakdown).
     *
     * @return array{0: AnalysisAxisRef, 1: ?AnalysisAxisRef}
     */
    public function fromLegacyDimension(
        AnalysisDimensionKey $dimensionKey,
        AnalysisDimensionGrain $grain,
    ): array {
        if ($dimensionKey->isTemporalPrimary()) {
            return [AnalysisAxisRef::time($grain), null];
        }

        if ($grain->isTemporal()) {
            return [AnalysisAxisRef::time($grain), AnalysisAxisRef::breakdown($dimensionKey)];
        }

        return [AnalysisAxisRef::breakdown($dimensionKey), null];
    }
}
