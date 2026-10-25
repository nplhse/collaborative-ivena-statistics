<?php

declare(strict_types=1);

namespace App\Storage\Application\Capacity;

use App\Storage\Domain\Enum\CapacityLevel;

/**
 * Where the capacity levels start, in percent of the capacity reference (ADR 017, "Capacity levels").
 * Exceeded always starts at 100 %. This is the only place that turns usage into a level.
 */
final readonly class CapacityThresholds
{
    public const int EXCEEDED_PERCENT = 100;

    public function __construct(
        public int $noticePercent = 80,
        public int $warningPercent = 90,
        public int $criticalPercent = 99,
    ) {
        if (!(0 < $noticePercent && $noticePercent < $warningPercent && $warningPercent < $criticalPercent && $criticalPercent < self::EXCEEDED_PERCENT)) {
            throw new \InvalidArgumentException(sprintf('Storage thresholds must increase strictly between 0 and 100, got %d / %d / %d.', $noticePercent, $warningPercent, $criticalPercent));
        }
    }

    /**
     * Unknown without a usage or without a positive capacity.
     */
    public function levelFor(?int $usedBytes, ?int $capacityBytes): CapacityLevel
    {
        if (null === $usedBytes || null === $capacityBytes || $capacityBytes <= 0) {
            return CapacityLevel::Unknown;
        }

        // Integer comparison of used / capacity >= percent / 100, without rounding.
        $used = $usedBytes * 100;

        return match (true) {
            $used >= self::EXCEEDED_PERCENT * $capacityBytes => CapacityLevel::Exceeded,
            $used >= $this->criticalPercent * $capacityBytes => CapacityLevel::Critical,
            $used >= $this->warningPercent * $capacityBytes => CapacityLevel::Warning,
            $used >= $this->noticePercent * $capacityBytes => CapacityLevel::Notice,
            default => CapacityLevel::Normal,
        };
    }
}
