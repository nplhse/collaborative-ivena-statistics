<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Domain\DTO\MetricDefinition;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricFormat;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;

final readonly class MetricValueFormatter
{
    public function __construct(
        private MetricRegistry $metricRegistry,
    ) {
    }

    /**
     * @param array<string, int|float|null> $metrics
     *
     * @return array<string, string>
     */
    public function formatMany(array $metrics): array
    {
        $formatted = [];
        foreach ($metrics as $key => $value) {
            if (!$this->metricRegistry->has($key)) {
                $formatted[$key] = $this->formatRaw($value);

                continue;
            }

            $formatted[$key] = $this->format($this->metricRegistry->get($key), $value);
        }

        return $formatted;
    }

    public function format(MetricDefinition $metric, int|float|null $value): string
    {
        if (null === $value) {
            return '—';
        }

        return match ($metric->defaultFormat) {
            MetricFormat::Integer => number_format((float) $value, 0, ',', '.'),
            MetricFormat::Decimal => number_format((float) $value, $metric->defaultPrecision, ',', '.'),
            MetricFormat::Percent => number_format((float) $value, $metric->defaultPrecision, ',', '.').' %',
            MetricFormat::Minutes => number_format((float) $value, $metric->defaultPrecision, ',', '.').' min',
        };
    }

    private function formatRaw(int|float|null $value): string
    {
        if (null === $value) {
            return '—';
        }

        return number_format((float) $value, 2, ',', '.');
    }
}
