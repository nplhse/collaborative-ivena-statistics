<?php

declare(strict_types=1);

namespace App\Statistics\Domain\Model;

final class TransportTimeStatsView
{
    /**
     * @param string[]                 $buckets
     * @param TransportTimeMetricRow[] $rows
     */
    public function __construct(
        private array $buckets,
        private array $rows,
        private ?float $mean,
        private ?float $variance,
        private ?float $stddev,
        private ?\DateTimeImmutable $computedAt,
    ) {
    }

    /**
     * @param string[]                 $buckets
     * @param TransportTimeMetricRow[] $rows
     */
    public static function empty(array $buckets, array $rows): self
    {
        return new self(
            $buckets,
            $rows,
            null,
            null,
            null,
            null
        );
    }

    /**
     * @return string[]
     */
    public function getBuckets(): array
    {
        return $this->buckets;
    }

    /**
     * @return TransportTimeMetricRow[]
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    public function getMean(): ?float
    {
        return $this->mean;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function getVariance(): ?float
    {
        return $this->variance;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function getStddev(): ?float
    {
        return $this->stddev;
    }

    public function getComputedAt(): ?\DateTimeImmutable
    {
        return $this->computedAt;
    }
}
