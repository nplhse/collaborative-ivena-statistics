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
    case PrevalenceRate = 'prevalence_rate';
    case InfectionRate = 'infection_rate';
    case MeanTransportTime = 'mean_transport_time';
    case MedianTransportTime = 'median_transport_time';
    case P25TransportTime = 'p25_transport_time';
    case P75TransportTime = 'p75_transport_time';
    case P90TransportTime = 'p90_transport_time';
    case HospitalCount = 'hospital_count';
    case SumBeds = 'sum_beds';
    case AvgBeds = 'avg_beds';
    case MinBeds = 'min_beds';
    case MaxBeds = 'max_beds';
    case TotalAllocations = 'total_allocations';
    case AvgAllocationsPerHospital = 'avg_allocations_per_hospital';
    case MinAllocations = 'min_allocations';
    case MaxAllocations = 'max_allocations';
    case BedsDistribution = 'beds_distribution';
    case AllocationsPerHospitalDistribution = 'allocations_per_hospital_distribution';
    case TransportTimeDistribution = 'transport_time_distribution';
    case TransportTimePerHospitalDistribution = 'transport_time_per_hospital_distribution';

    public function registryKey(): string
    {
        return match ($this) {
            self::AllocationCount => 'count',
            default => $this->value,
        };
    }

    public function defaultForDataSource(AnalysisDataSourceKey $dataSourceKey): bool
    {
        return match ($dataSourceKey) {
            AnalysisDataSourceKey::Allocations => self::AllocationCount === $this,
            AnalysisDataSourceKey::Hospitals => self::HospitalCount === $this,
        };
    }

    public function metricCategory(): ExplorerMetricCategory
    {
        return match ($this) {
            self::AllocationCount, self::HospitalCount => ExplorerMetricCategory::Count,
            self::PercentOfTotal => ExplorerMetricCategory::Distribution,
            self::ResusRate,
            self::CprRate,
            self::ShockRate,
            self::VentilationRate,
            self::CathlabRate,
            self::PregnancyRate,
            self::WorkAccidentRate,
            self::WithPhysicianRate,
            self::PrevalenceRate,
            self::InfectionRate => ExplorerMetricCategory::Rate,
            self::MeanTransportTime,
            self::MedianTransportTime,
            self::P25TransportTime,
            self::P75TransportTime,
            self::P90TransportTime => ExplorerMetricCategory::Statistical,
            self::SumBeds,
            self::AvgBeds,
            self::MinBeds,
            self::MaxBeds,
            self::TotalAllocations,
            self::AvgAllocationsPerHospital,
            self::MinAllocations,
            self::MaxAllocations => ExplorerMetricCategory::NumericAggregate,
            self::BedsDistribution,
            self::AllocationsPerHospitalDistribution,
            self::TransportTimeDistribution,
            self::TransportTimePerHospitalDistribution => ExplorerMetricCategory::DistributionProfile,
        };
    }

    public function isDistributionProfile(): bool
    {
        return ExplorerMetricCategory::DistributionProfile === $this->metricCategory();
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
            self::PrevalenceRate,
            self::InfectionRate,
            self::MeanTransportTime,
            self::MedianTransportTime,
            self::P25TransportTime,
            self::P75TransportTime,
            self::P90TransportTime,
            self::TransportTimeDistribution,
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

    /**
     * @return list<self>
     */
    public static function hospitalsCatalog(): array
    {
        return [
            self::HospitalCount,
            self::PercentOfTotal,
            self::SumBeds,
            self::AvgBeds,
            self::MinBeds,
            self::MaxBeds,
            self::TotalAllocations,
            self::AvgAllocationsPerHospital,
            self::MinAllocations,
            self::MaxAllocations,
            self::BedsDistribution,
            self::AllocationsPerHospitalDistribution,
            self::TransportTimePerHospitalDistribution,
        ];
    }

    /**
     * @return list<self>
     */
    public static function enabledHospitalsCatalog(): array
    {
        return self::hospitalsCatalog();
    }

    /**
     * @return list<self>
     */
    public static function primaryHospitalMetricChoices(): array
    {
        return array_values(array_filter(
            self::enabledHospitalsCatalog(),
            static fn (self $key): bool => self::PercentOfTotal !== $key,
        ));
    }

    public static function defaultFor(AnalysisDataSourceKey $dataSourceKey): self
    {
        return match ($dataSourceKey) {
            AnalysisDataSourceKey::Allocations => self::AllocationCount,
            AnalysisDataSourceKey::Hospitals => self::HospitalCount,
        };
    }

    public function isAllocationDerived(): bool
    {
        return match ($this) {
            self::TotalAllocations,
            self::AvgAllocationsPerHospital,
            self::MinAllocations,
            self::MaxAllocations => true,
            default => false,
        };
    }

    public function explorerGroupTranslationKey(): string
    {
        return match ($this) {
            self::AllocationCount, self::HospitalCount => 'stats.analysis_explorer.metric_group.counts',
            self::PrevalenceRate => 'stats.analysis_explorer.metric_group.shares',
            self::ResusRate,
            self::CprRate,
            self::ShockRate,
            self::VentilationRate,
            self::CathlabRate,
            self::PregnancyRate,
            self::WorkAccidentRate,
            self::WithPhysicianRate,
            self::InfectionRate => 'stats.analysis_explorer.metric_group.clinical_rates',
            self::SumBeds,
            self::AvgBeds,
            self::MinBeds,
            self::MaxBeds,
            self::BedsDistribution => 'stats.analysis_explorer.metric_group.beds',
            self::TotalAllocations,
            self::AvgAllocationsPerHospital,
            self::MinAllocations,
            self::MaxAllocations,
            self::AllocationsPerHospitalDistribution => 'stats.analysis_explorer.metric_group.allocations',
            self::TransportTimeDistribution,
            self::TransportTimePerHospitalDistribution,
            self::MeanTransportTime,
            self::MedianTransportTime,
            self::P25TransportTime,
            self::P75TransportTime,
            self::P90TransportTime => 'stats.analysis_explorer.metric_group.transport_times',
            default => 'stats.analysis_explorer.metric_group.counts',
        };
    }

    /**
     * @param list<self> $metricKeys
     *
     * @return list<string>
     */
    public static function additionalTableMetricValues(array $metricKeys, self $visualMetricKey): array
    {
        $values = [];
        foreach ($metricKeys as $metricKey) {
            if ($metricKey === $visualMetricKey || self::PercentOfTotal === $metricKey) {
                continue;
            }

            $values[] = $metricKey->value;
        }

        return $values;
    }
}
