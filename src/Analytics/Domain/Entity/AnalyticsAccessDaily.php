<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Counted accesses (page views, #950) per Berlin calendar day, split by authentication.
 *
 * A day has rows only when its accesses could be counted, also when the count is zero; a day
 * without rows is outside the counted period. See docs/04-features/analytics/aggregation-and-retention.md.
 */
#[ORM\Entity]
#[ORM\Table(name: 'analytics_access_daily')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_access_daily_grain', columns: ['date', 'is_authenticated'])]
#[ORM\Index(name: 'idx_analytics_access_daily_date', columns: ['date'])]
class AnalyticsAccessDaily
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column]
    private bool $isAuthenticated;

    #[ORM\Column]
    private int $accessCount;

    public function __construct(\DateTimeImmutable $date, bool $isAuthenticated, int $accessCount)
    {
        $this->date = $date;
        $this->isAuthenticated = $isAuthenticated;
        $this->accessCount = $accessCount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function isAuthenticated(): bool
    {
        return $this->isAuthenticated;
    }

    public function getAccessCount(): int
    {
        return $this->accessCount;
    }
}
