<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;

final readonly class AnalysisDimensionGrainResolver
{
    public function resolveFromString(
        AnalysisDimensionKey $dimensionKey,
        ?string $grain,
        DataSourceCapabilities $capabilities,
    ): AnalysisDimensionGrain {
        $parsed = null !== $grain && '' !== $grain
            ? AnalysisDimensionGrain::tryFrom($grain)
            : null;

        return $this->resolve($dimensionKey, $parsed, $capabilities);
    }

    public function resolveFromEnum(
        AnalysisDimensionKey $dimensionKey,
        ?AnalysisDimensionGrain $grain,
        DataSourceCapabilities $capabilities,
    ): AnalysisDimensionGrain {
        return $this->resolve($dimensionKey, $grain, $capabilities);
    }

    private function resolve(
        AnalysisDimensionKey $dimensionKey,
        ?AnalysisDimensionGrain $grain,
        DataSourceCapabilities $capabilities,
    ): AnalysisDimensionGrain {
        if (!$dimensionKey->isTemporalPrimary() && !$grain instanceof AnalysisDimensionGrain) {
            $grain = AnalysisDimensionGrain::Total;
        }

        $allowed = $capabilities->timeGrainsFor($dimensionKey);
        if ($grain instanceof AnalysisDimensionGrain && \in_array($grain, $allowed, true)) {
            return $grain;
        }

        if ($dimensionKey->isTemporalPrimary()) {
            return $capabilities->defaultTimeGrain;
        }

        return AnalysisDimensionGrain::Total;
    }
}
