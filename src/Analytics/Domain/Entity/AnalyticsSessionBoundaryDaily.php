<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Entity;

use App\Analytics\Domain\Enum\SessionBoundaryKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'analytics_session_boundary_daily')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_session_boundary_daily', columns: ['date', 'route_name', 'kind'])]
#[ORM\Index(name: 'idx_analytics_session_boundary_daily_date', columns: ['date'])]
class AnalyticsSessionBoundaryDaily
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 180)]
    private string $routeName;

    #[ORM\Column(length: 8, enumType: SessionBoundaryKind::class)]
    private SessionBoundaryKind $kind;

    #[ORM\Column]
    private int $sessionCount;

    public function __construct(
        \DateTimeImmutable $date,
        string $routeName,
        SessionBoundaryKind $kind,
        int $sessionCount,
    ) {
        $this->date = $date;
        $this->routeName = $routeName;
        $this->kind = $kind;
        $this->sessionCount = $sessionCount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getRouteName(): string
    {
        return $this->routeName;
    }

    public function getKind(): SessionBoundaryKind
    {
        return $this->kind;
    }

    public function getSessionCount(): int
    {
        return $this->sessionCount;
    }
}
