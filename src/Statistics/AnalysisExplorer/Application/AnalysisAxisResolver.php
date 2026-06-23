<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;

final readonly class AnalysisAxisResolver
{
    public function resolve(
        AnalysisAxisRef $axis,
        DataSourceCapabilities $capabilities,
    ): AnalysisAxisRef {
        $grain = $axis->dimensionKey->isTemporalPrimary()
            ? $this->resolveTemporalGrain($axis->grain, $capabilities)
            : $this->resolveBreakdownGrain($axis->grain);

        return new AnalysisAxisRef($axis->dimensionKey, $grain);
    }

    public function resolveFromStrings(
        string $dimension,
        ?string $grainValue,
        DataSourceCapabilities $capabilities,
    ): AnalysisAxisRef {
        $dimensionKey = AnalysisDimensionKey::tryFrom($dimension) ?? AnalysisDimensionKey::Time;
        $grain = \is_string($grainValue) ? AnalysisDimensionGrain::tryFrom($grainValue) : null;

        return $this->resolve(new AnalysisAxisRef($dimensionKey, $grain), $capabilities);
    }

    private function resolveTemporalGrain(
        ?AnalysisDimensionGrain $grain,
        DataSourceCapabilities $capabilities,
    ): AnalysisDimensionGrain {
        if ($grain instanceof AnalysisDimensionGrain
            && \in_array($grain, $capabilities->timeGrains, true)
            && AnalysisDimensionGrain::Total !== $grain) {
            return $grain;
        }

        return $capabilities->defaultTimeGrain;
    }

    private function resolveBreakdownGrain(?AnalysisDimensionGrain $grain): AnalysisDimensionGrain
    {
        if ($grain instanceof AnalysisDimensionGrain) {
            return $grain;
        }

        return AnalysisDimensionGrain::Total;
    }
}
