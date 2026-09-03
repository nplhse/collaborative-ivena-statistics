<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\TimeSeries\TimeSeriesGrainResolver;

final readonly class AnalysisAxisResolver
{
    public function resolve(
        AnalysisAxisRef $axis,
        DataSourceCapabilities $capabilities,
        ?StatisticsFilterPeriod $period = null,
    ): AnalysisAxisRef {
        $grain = $axis->dimensionKey->isTemporalPrimary()
            ? $this->resolveTemporalGrain($axis->grain, $capabilities, $period)
            : $this->resolveBreakdownGrain($axis->grain);

        return new AnalysisAxisRef($axis->dimensionKey, $grain);
    }

    public function resolveFromStrings(
        string $dimension,
        ?string $grainValue,
        DataSourceCapabilities $capabilities,
        ?StatisticsFilterPeriod $period = null,
    ): AnalysisAxisRef {
        $dimensionKey = AnalysisDimensionKey::tryFrom($dimension) ?? AnalysisDimensionKey::Time;
        $grain = \is_string($grainValue) ? AnalysisDimensionGrain::tryFrom($grainValue) : null;

        return $this->resolve(new AnalysisAxisRef($dimensionKey, $grain), $capabilities, $period);
    }

    private function resolveTemporalGrain(
        ?AnalysisDimensionGrain $grain,
        DataSourceCapabilities $capabilities,
        ?StatisticsFilterPeriod $period,
    ): AnalysisDimensionGrain {
        if ($grain instanceof AnalysisDimensionGrain
            && \in_array($grain, $capabilities->timeGrains, true)
            && AnalysisDimensionGrain::Total !== $grain) {
            $resolved = $grain;
        } else {
            $resolved = $capabilities->defaultTimeGrain;
        }

        if (!$period instanceof StatisticsFilterPeriod) {
            return $resolved;
        }

        $clamped = AnalysisDimensionGrain::tryFrom(
            TimeSeriesGrainResolver::clampGrainValue($period, $resolved->value),
        );

        if ($clamped instanceof AnalysisDimensionGrain
            && \in_array($clamped, $capabilities->timeGrains, true)) {
            return $clamped;
        }

        return $resolved;
    }

    private function resolveBreakdownGrain(?AnalysisDimensionGrain $grain): AnalysisDimensionGrain
    {
        if ($grain instanceof AnalysisDimensionGrain) {
            return $grain;
        }

        return AnalysisDimensionGrain::Total;
    }
}
