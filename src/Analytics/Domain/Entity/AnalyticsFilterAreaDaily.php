<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Entity;

use App\Analytics\Domain\Enum\FeatureArea;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'analytics_filter_area_daily')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_filter_area_daily', columns: ['date', 'feature_area'])]
#[ORM\Index(name: 'idx_analytics_filter_area_daily_date', columns: ['date'])]
class AnalyticsFilterAreaDaily
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 32, enumType: FeatureArea::class)]
    private FeatureArea $featureArea;

    #[ORM\Column]
    private int $withFilters;

    #[ORM\Column]
    private int $withoutFilters;

    public function __construct(
        \DateTimeImmutable $date,
        FeatureArea $featureArea,
        int $withFilters,
        int $withoutFilters,
    ) {
        $this->date = $date;
        $this->featureArea = $featureArea;
        $this->withFilters = $withFilters;
        $this->withoutFilters = $withoutFilters;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getFeatureArea(): FeatureArea
    {
        return $this->featureArea;
    }

    public function getWithFilters(): int
    {
        return $this->withFilters;
    }

    public function getWithoutFilters(): int
    {
        return $this->withoutFilters;
    }
}
