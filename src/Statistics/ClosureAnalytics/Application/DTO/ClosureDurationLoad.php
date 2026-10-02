<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

use App\Statistics\ClosureAnalytics\Application\ClosureDurationFormatter;

final readonly class ClosureDurationLoad
{
    /**
     * @param list<ClosureDurationDistributionGroup> $specialities
     * @param list<ClosureDurationDistributionGroup> $reasons
     */
    public function __construct(
        public int $eventCount,
        public ?float $medianSeconds,
        public ?float $meanSeconds,
        public ?int $minimumSeconds,
        public ?int $maximumSeconds,
        public ?int $longestPhaseSeconds,
        public int $phaseCount,
        public bool $pausesComputable,
        public ?float $medianPauseSeconds,
        public ?int $minimumPauseSeconds,
        public ?int $maximumPauseSeconds,
        public int $evaluableSeconds,
        public int $noneSeconds,
        public int $singleDepartmentSeconds,
        public int $multipleDepartmentsSeconds,
        public array $specialities,
        public bool $specialitiesTruncated,
        public array $reasons,
        public bool $reasonsTruncated,
    ) {
    }

    public static function empty(): self
    {
        return new self(
            0,
            null,
            null,
            null,
            null,
            null,
            0,
            false,
            null,
            null,
            null,
            0,
            0,
            0,
            0,
            [],
            false,
            [],
            false,
        );
    }

    /**
     * @param list<ClosureDurationDistributionGroup> $reasons
     */
    public function withReasons(array $reasons): self
    {
        return new self(
            $this->eventCount,
            $this->medianSeconds,
            $this->meanSeconds,
            $this->minimumSeconds,
            $this->maximumSeconds,
            $this->longestPhaseSeconds,
            $this->phaseCount,
            $this->pausesComputable,
            $this->medianPauseSeconds,
            $this->minimumPauseSeconds,
            $this->maximumPauseSeconds,
            $this->evaluableSeconds,
            $this->noneSeconds,
            $this->singleDepartmentSeconds,
            $this->multipleDepartmentsSeconds,
            $this->specialities,
            $this->specialitiesTruncated,
            $reasons,
            $this->reasonsTruncated,
        );
    }

    public function atLeastOneSeconds(): int
    {
        return $this->singleDepartmentSeconds + $this->multipleDepartmentsSeconds;
    }

    public function hasEvaluableTime(): bool
    {
        return $this->evaluableSeconds > 0;
    }

    public function sharePercent(int $seconds): float
    {
        if ($this->evaluableSeconds <= 0) {
            return 0.0;
        }

        return ((float) $seconds / (float) $this->evaluableSeconds) * 100.0;
    }

    public function normalizedSeconds(int $seconds): int
    {
        if ($this->evaluableSeconds <= 0) {
            return 0;
        }

        return (int) round((float) $seconds / (float) $this->evaluableSeconds * 86400.0);
    }

    public function minutes(int|float|null $seconds): ?int
    {
        if (null === $seconds) {
            return null;
        }

        return ClosureDurationFormatter::minutesFromSeconds($seconds);
    }
}
