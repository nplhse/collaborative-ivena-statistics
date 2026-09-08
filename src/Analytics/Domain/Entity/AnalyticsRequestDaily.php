<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Entity;

use App\Analytics\Domain\Enum\FeatureArea;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'analytics_request_daily')]
#[ORM\UniqueConstraint(
    name: 'uniq_analytics_request_daily_grain',
    columns: ['date', 'feature_area', 'route_name', 'is_authenticated', 'user_role'],
    options: ['nulls_not_distinct' => true],
)]
#[ORM\Index(name: 'idx_analytics_request_daily_date', columns: ['date'])]
class AnalyticsRequestDaily
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 32, enumType: FeatureArea::class)]
    private FeatureArea $featureArea;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $routeName;

    #[ORM\Column]
    private bool $isAuthenticated;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $userRole;

    #[ORM\Column]
    private int $requestCount;

    #[ORM\Column]
    private int $errorCount;

    #[ORM\Column]
    private int $sumDurationMs;

    #[ORM\Column]
    private int $sumDbQueryCount;

    #[ORM\Column]
    private int $sumDbTimeMs;

    public function __construct(
        \DateTimeImmutable $date,
        FeatureArea $featureArea,
        ?string $routeName,
        bool $isAuthenticated,
        ?string $userRole,
        int $requestCount,
        int $errorCount,
        int $sumDurationMs,
        int $sumDbQueryCount,
        int $sumDbTimeMs,
    ) {
        $this->date = $date;
        $this->featureArea = $featureArea;
        $this->routeName = $routeName;
        $this->isAuthenticated = $isAuthenticated;
        $this->userRole = $userRole;
        $this->requestCount = $requestCount;
        $this->errorCount = $errorCount;
        $this->sumDurationMs = $sumDurationMs;
        $this->sumDbQueryCount = $sumDbQueryCount;
        $this->sumDbTimeMs = $sumDbTimeMs;
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

    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    public function isAuthenticated(): bool
    {
        return $this->isAuthenticated;
    }

    public function getUserRole(): ?string
    {
        return $this->userRole;
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function getSumDurationMs(): int
    {
        return $this->sumDurationMs;
    }

    public function getSumDbQueryCount(): int
    {
        return $this->sumDbQueryCount;
    }

    public function getSumDbTimeMs(): int
    {
        return $this->sumDbTimeMs;
    }
}
