<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\DTO;

use App\Statistics\GenericAnalysis\Domain\Enum\MetricFormat;

final readonly class GenericAnalysisTableMetricColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public MetricFormat $format,
        public string $align = 'end',
    ) {
    }
}
