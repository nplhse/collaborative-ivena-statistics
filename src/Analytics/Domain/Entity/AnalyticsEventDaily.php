<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Entity;

use App\Analytics\Domain\Enum\FeatureArea;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'analytics_event_daily')]
#[ORM\UniqueConstraint(
    name: 'uniq_analytics_event_daily_grain',
    columns: ['date', 'event_name', 'feature_area', 'user_role'],
    options: ['nulls_not_distinct' => true],
)]
#[ORM\Index(name: 'idx_analytics_event_daily_date', columns: ['date'])]
class AnalyticsEventDaily
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 120)]
    private string $eventName;

    #[ORM\Column(length: 32, nullable: true, enumType: FeatureArea::class)]
    private ?FeatureArea $featureArea;

    #[ORM\Column(length: 64)]
    private string $userRole;

    #[ORM\Column]
    private int $eventCount;

    public function __construct(
        \DateTimeImmutable $date,
        string $eventName,
        ?FeatureArea $featureArea,
        string $userRole,
        int $eventCount,
    ) {
        $this->date = $date;
        $this->eventName = $eventName;
        $this->featureArea = $featureArea;
        $this->userRole = $userRole;
        $this->eventCount = $eventCount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    public function getFeatureArea(): ?FeatureArea
    {
        return $this->featureArea;
    }

    public function getUserRole(): string
    {
        return $this->userRole;
    }

    public function getEventCount(): int
    {
        return $this->eventCount;
    }
}
