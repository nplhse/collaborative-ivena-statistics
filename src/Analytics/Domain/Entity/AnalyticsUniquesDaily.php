<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'analytics_uniques_daily')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_uniques_daily_date', columns: ['date'])]
class AnalyticsUniquesDaily
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column]
    private int $dau;

    #[ORM\Column]
    private int $distinctVisitors;

    #[ORM\Column]
    private int $distinctSessions;

    public function __construct(
        \DateTimeImmutable $date,
        int $dau,
        int $distinctVisitors,
        int $distinctSessions,
    ) {
        $this->date = $date;
        $this->dau = $dau;
        $this->distinctVisitors = $distinctVisitors;
        $this->distinctSessions = $distinctSessions;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getDau(): int
    {
        return $this->dau;
    }

    public function getDistinctVisitors(): int
    {
        return $this->distinctVisitors;
    }

    public function getDistinctSessions(): int
    {
        return $this->distinctSessions;
    }
}
