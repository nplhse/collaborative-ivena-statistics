<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'analytics_filter_param_daily')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_filter_param_daily', columns: ['date', 'param_name'])]
#[ORM\Index(name: 'idx_analytics_filter_param_daily_date', columns: ['date'])]
class AnalyticsFilterParamDaily
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 255)]
    private string $paramName;

    #[ORM\Column]
    private int $usageCount;

    public function __construct(
        \DateTimeImmutable $date,
        string $paramName,
        int $usageCount,
    ) {
        $this->date = $date;
        $this->paramName = $paramName;
        $this->usageCount = $usageCount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getParamName(): string
    {
        return $this->paramName;
    }

    public function getUsageCount(): int
    {
        return $this->usageCount;
    }
}
