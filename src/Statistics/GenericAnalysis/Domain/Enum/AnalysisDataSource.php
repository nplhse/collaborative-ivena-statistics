<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum AnalysisDataSource: string
{
    case Allocations = 'allocations';
    case Hospitals = 'hospitals';

    public function distributionBaseMetricKey(): string
    {
        return match ($this) {
            self::Allocations => 'count',
            self::Hospitals => 'hospital_count',
        };
    }

    public function defaultMetricKey(): string
    {
        return $this->distributionBaseMetricKey();
    }
}
