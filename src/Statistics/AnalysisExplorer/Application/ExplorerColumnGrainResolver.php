<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;

final readonly class ExplorerColumnGrainResolver
{
    public function affectsQuery(AnalysisDimensionKey $columnDimension): bool
    {
        return $columnDimension->isTemporalPrimary();
    }

    public function defaultFor(
        AnalysisAxisRef $rowAxis,
        AnalysisDimensionKey $columnDimension,
        DataSourceCapabilities $capabilities,
    ): AnalysisDimensionGrain {
        if (!$this->affectsQuery($columnDimension)) {
            return AnalysisDimensionGrain::Total;
        }

        if ($rowAxis->dimensionKey->isTemporalPrimary()) {
            return $rowAxis->resolvedGrain();
        }

        return $capabilities->defaultTimeGrain;
    }

    public function resolve(
        AnalysisAxisRef $rowAxis,
        AnalysisDimensionKey $columnDimension,
        ?AnalysisDimensionGrain $submittedGrain,
        DataSourceCapabilities $capabilities,
    ): AnalysisDimensionGrain {
        if (!$this->affectsQuery($columnDimension)) {
            return AnalysisDimensionGrain::Total;
        }

        if ($rowAxis->dimensionKey->isTemporalPrimary()) {
            return $rowAxis->resolvedGrain();
        }

        $allowedGrains = $capabilities->timeGrainsFor($columnDimension);
        if ($submittedGrain instanceof AnalysisDimensionGrain
            && \in_array($submittedGrain, $allowedGrains, true)
            && AnalysisDimensionGrain::Total !== $submittedGrain) {
            return $submittedGrain;
        }

        return $this->defaultFor($rowAxis, $columnDimension, $capabilities);
    }
}
