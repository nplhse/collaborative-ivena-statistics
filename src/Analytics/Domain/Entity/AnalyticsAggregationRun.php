<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'analytics_aggregation_run')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_aggregation_run_date', columns: ['date'])]
class AnalyticsAggregationRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column]
    private \DateTimeImmutable $aggregatedAt;

    #[ORM\Column]
    private int $rawRequestCount;

    #[ORM\Column]
    private int $rawEventCount;

    #[ORM\Column]
    private int $aggregateRowsWritten;

    public function __construct(
        \DateTimeImmutable $date,
        int $rawRequestCount,
        int $rawEventCount,
        int $aggregateRowsWritten,
        ?\DateTimeImmutable $aggregatedAt = null,
    ) {
        $this->date = $date;
        $this->rawRequestCount = $rawRequestCount;
        $this->rawEventCount = $rawEventCount;
        $this->aggregateRowsWritten = $aggregateRowsWritten;
        $this->aggregatedAt = $aggregatedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getAggregatedAt(): \DateTimeImmutable
    {
        return $this->aggregatedAt;
    }

    public function getRawRequestCount(): int
    {
        return $this->rawRequestCount;
    }

    public function getRawEventCount(): int
    {
        return $this->rawEventCount;
    }

    public function getAggregateRowsWritten(): int
    {
        return $this->aggregateRowsWritten;
    }
}
