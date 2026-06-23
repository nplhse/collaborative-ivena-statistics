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

        $floatValue = (float) $value;

        return match ($metric->defaultFormat) {
            MetricFormat::Integer => number_format($floatValue, 0, ',', '.'),
            MetricFormat::Decimal => $this->formatAdaptiveNumber($floatValue, $metric->defaultPrecision),
            MetricFormat::Percent => $this->formatAdaptiveNumber($floatValue, $metric->defaultPrecision).' %',
            MetricFormat::Minutes => $this->formatAdaptiveNumber($floatValue, $metric->defaultPrecision).' min',
        };
    }

    private function formatAdaptiveNumber(float $value, int $maxPrecision): string
    {
        return number_format($value, $this->resolveDecimalPlaces($value, $maxPrecision), ',', '.');
    }

    private function resolveDecimalPlaces(float $value, int $maxPrecision): int
    {
        if ($maxPrecision <= 0 || $this->isWholeNumber($value)) {
            return 0;
        }

        $asString = rtrim(sprintf('%.'.$maxPrecision.'f', $value), '0');
        $dotPosition = strpos($asString, '.');
        $fraction = false !== $dotPosition ? substr($asString, $dotPosition + 1) : '';

        return strlen($fraction);
    }

    private function isWholeNumber(float $value): bool
    {
        return abs($value - round($value)) < 1e-9;
    }

    private function formatRaw(int|float|null $value): string
    {
        if (null === $value) {
            return '—';
        }

        return $this->formatAdaptiveNumber((float) $value, 2);
    }
}
