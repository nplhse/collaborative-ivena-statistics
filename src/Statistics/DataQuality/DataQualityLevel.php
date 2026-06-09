<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality;

enum DataQualityLevel: string
{
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';

    public function score(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Low => 'stats.data_quality.level.low',
            self::Medium => 'stats.data_quality.level.medium',
            self::High => 'stats.data_quality.level.high',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Low => 'bg-red-lt',
            self::Medium => 'bg-yellow-lt',
            self::High => 'bg-green-lt',
        };
    }

    public function statusDotClass(): string
    {
        return match ($this) {
            self::Low => 'bg-red',
            self::Medium => 'bg-yellow',
            self::High => 'bg-green',
        };
    }

    public static function fromScore(float $averageScore): self
    {
        if ($averageScore < DataQualityThresholds::OVERALL_LOW_MAX) {
            return self::Low;
        }

        if ($averageScore < DataQualityThresholds::OVERALL_MEDIUM_MAX) {
            return self::Medium;
        }

        return self::High;
    }

    public static function fromCoverageRatio(float $ratio): self
    {
        if ($ratio < DataQualityThresholds::COVERAGE_LOW_MAX) {
            return self::Low;
        }

        if ($ratio < DataQualityThresholds::COVERAGE_HIGH_MIN) {
            return self::Medium;
        }

        return self::High;
    }

    public static function fromShareRatio(float $ratio): self
    {
        if ($ratio < DataQualityThresholds::SHARE_LOW_MAX) {
            return self::Low;
        }

        if ($ratio < DataQualityThresholds::SHARE_MEDIUM_MAX) {
            return self::Medium;
        }

        return self::High;
    }

    public static function fromRepresentativenessDifference(float $difference): self
    {
        if ($difference < DataQualityThresholds::REPRESENTATIVENESS_HIGH_MAX) {
            return self::High;
        }

        if ($difference < DataQualityThresholds::REPRESENTATIVENESS_MEDIUM_MAX) {
            return self::Medium;
        }

        return self::Low;
    }
}
