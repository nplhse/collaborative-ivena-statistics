<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureDurationDistributionGroup
{
    /**
     * @param list<float> $outlierSeconds
     * @param list<int>   $valueSeconds
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $count,
        public float $medianSeconds,
        public float $meanSeconds,
        public int $minimumSeconds,
        public int $maximumSeconds,
        public float $lowerQuartileSeconds,
        public float $upperQuartileSeconds,
        public bool $showBox,
        public ?float $whiskerLowSeconds,
        public ?float $whiskerHighSeconds,
        public array $outlierSeconds,
        public array $valueSeconds,
    ) {
    }

    public function withLabel(string $label): self
    {
        return new self(
            $this->key,
            $label,
            $this->count,
            $this->medianSeconds,
            $this->meanSeconds,
            $this->minimumSeconds,
            $this->maximumSeconds,
            $this->lowerQuartileSeconds,
            $this->upperQuartileSeconds,
            $this->showBox,
            $this->whiskerLowSeconds,
            $this->whiskerHighSeconds,
            $this->outlierSeconds,
            $this->valueSeconds,
        );
    }
}
