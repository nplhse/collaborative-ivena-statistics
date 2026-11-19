<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rolling distinct users per Berlin calendar day (#1322): WAU counts the 7 days, MAU the 30 days ending with the day.
 *
 * A value is written only when its whole window still had raw rows at aggregation time, and it is never replaced by
 * an incomplete one; null means the window could not be counted. Daily users stay in `analytics_uniques_daily`.
 * See docs/04-features/analytics/aggregation-and-retention.md.
 */
#[ORM\Entity]
#[ORM\Table(name: 'analytics_active_users_daily')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_active_users_daily_date', columns: ['date'])]
class AnalyticsActiveUsersDaily
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(nullable: true)]
    private ?int $wau = null;

    #[ORM\Column(nullable: true)]
    private ?int $mau = null;

    public function __construct(\DateTimeImmutable $date)
    {
        $this->date = $date;
    }

    /**
     * Stores the counted windows; a null value (window not countable) keeps the value stored before.
     */
    public function record(?int $wau, ?int $mau): void
    {
        $this->wau = $wau ?? $this->wau;
        $this->mau = $mau ?? $this->mau;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getWau(): ?int
    {
        return $this->wau;
    }

    public function getMau(): ?int
    {
        return $this->mau;
    }
}
