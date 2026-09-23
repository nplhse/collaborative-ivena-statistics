<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;

/**
 * Derived assistant mode. Not stored and not a separate execution path.
 */
enum AnalysisShape: string
{
    case TimeSeries = 'time_series';
    case Distribution = 'distribution';
    case Matrix = 'matrix';

    public static function fromConfig(AnalysisViewConfig $config): self
    {
        if ($config->visualMetricKey->isDistributionProfile()
            || (!$config->rowAxis->dimensionKey->isTemporalPrimary() && !$config->hasColumnAxis())
        ) {
            return self::Distribution;
        }

        if ($config->rowAxis->dimensionKey->isTemporalPrimary()) {
            return self::TimeSeries;
        }

        return self::Matrix;
    }
}
