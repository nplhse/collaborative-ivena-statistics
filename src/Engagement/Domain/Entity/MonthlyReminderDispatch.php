<?php

declare(strict_types=1);

namespace App\Engagement\Domain\Entity;

use App\Allocation\Domain\Entity\Hospital;
use App\Engagement\Infrastructure\Repository\MonthlyReminderDispatchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonthlyReminderDispatchRepository::class)]
#[ORM\Table(name: 'monthly_reminder_dispatch')]
#[ORM\UniqueConstraint(name: 'uniq_monthly_reminder_dispatch', columns: ['hospital_id', 'reporting_period', 'trigger'])]
class MonthlyReminderDispatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Hospital::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Hospital $hospital,
        #[ORM\Column(length: 7)]
        private string $reportingPeriod,
        #[ORM\Column(length: 16)]
        private string $trigger,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $sentAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHospital(): Hospital
    {
        return $this->hospital;
    }

    public function getReportingPeriod(): string
    {
        return $this->reportingPeriod;
    }

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
