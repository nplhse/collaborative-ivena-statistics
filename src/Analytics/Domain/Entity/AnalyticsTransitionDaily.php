<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'analytics_transition_daily')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_transition_daily', columns: ['date', 'from_route', 'to_route'])]
#[ORM\Index(name: 'idx_analytics_transition_daily_date', columns: ['date'])]
class AnalyticsTransitionDaily
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 180)]
    private string $fromRoute;

    #[ORM\Column(length: 180)]
    private string $toRoute;

    #[ORM\Column]
    private int $transitionCount;

    public function __construct(
        \DateTimeImmutable $date,
        string $fromRoute,
        string $toRoute,
        int $transitionCount,
    ) {
        $this->date = $date;
        $this->fromRoute = $fromRoute;
        $this->toRoute = $toRoute;
        $this->transitionCount = $transitionCount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getFromRoute(): string
    {
        return $this->fromRoute;
    }

    public function getToRoute(): string
    {
        return $this->toRoute;
    }

    public function getTransitionCount(): int
    {
        return $this->transitionCount;
    }
}
