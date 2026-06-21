<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum AnalysisMetricKey: string
{
    case AllocationCount = 'allocation_count';
    case PercentOfTotal = 'percent_of_total';
    case ResusRate = 'resus_rate';
    case CprRate = 'cpr_rate';
    case ShockRate = 'shock_rate';
    case VentilationRate = 'ventilation_rate';
    case CathlabRate = 'cathlab_rate';
    case PregnancyRate = 'pregnancy_rate';
    case WorkAccidentRate = 'work_accident_rate';
    case WithPhysicianRate = 'with_physician_rate';
    case MeanTransportTime = 'mean_transport_time';
    case MedianTransportTime = 'median_transport_time';
    case P25TransportTime = 'p25_transport_time';
    case P75TransportTime = 'p75_transport_time';
    case P90TransportTime = 'p90_transport_time';

    public function registryKey(): string
    {
        return match ($this) {
            self::AllocationCount => 'count',
            default => $this->value,
        };
    }

    public function metricCategory(): ExplorerMetricCategory
    {
        return match ($this) {
            self::AllocationCount => ExplorerMetricCategory::Count,
            self::PercentOfTotal => ExplorerMetricCategory::Distribution,
            self::ResusRate,
            self::CprRate,
            self::ShockRate,
            self::VentilationRate,
            self::CathlabRate,
            self::PregnancyRate,
            self::WorkAccidentRate,
            self::WithPhysicianRate => ExplorerMetricCategory::Rate,
            self::MeanTransportTime,
            self::MedianTransportTime,
            self::P25TransportTime,
            self::P75TransportTime,
            self::P90TransportTime => ExplorerMetricCategory::Statistical,
        };
    }

    public function isChartable(): bool
    {
        return ExplorerMetricCategory::Statistical !== $this->metricCategory();
    }

    public function isEnabledInStepOne(): bool
    {
        return ExplorerMetricCategory::Statistical !== $this->metricCategory();
    }

    /**
     * @return list<self>
     */
    public static function allocationsCatalog(): array
    {
        return [
            self::AllocationCount,
            self::PercentOfTotal,
            self::ResusRate,
            self::CprRate,
            self::ShockRate,
            self::VentilationRate,
            self::CathlabRate,
            self::PregnancyRate,
            self::WorkAccidentRate,
            self::WithPhysicianRate,
            self::MeanTransportTime,
            self::MedianTransportTime,
            self::P25TransportTime,
            self::P75TransportTime,
            self::P90TransportTime,
        ];
    }

    /**
     * @return list<self>
     */
    public static function enabledAllocationsCatalog(): array
    {
        return array_values(array_filter(
            self::allocationsCatalog(),
            static fn (self $key): bool => $key->isEnabledInStepOne(),
        ));
    }

    /**
     * @return list<self>
     */
    public static function primaryMetricChoices(): array
    {
        return array_values(array_filter(
            self::enabledAllocationsCatalog(),
            static fn (self $key): bool => self::PercentOfTotal !== $key,
        ));
    }
}
