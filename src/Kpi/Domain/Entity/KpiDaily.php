<?php

declare(strict_types=1);

namespace App\Kpi\Domain\Entity;

use App\Allocation\Domain\Entity\Hospital;
use App\Kpi\Infrastructure\Repository\KpiDailyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KpiDailyRepository::class)]
#[ORM\Table(name: 'kpi_daily')]
#[ORM\UniqueConstraint(name: 'uniq_kpi_daily_date_hospital', columns: ['date', 'hospital_id'], options: ['nulls_not_distinct' => true])]
#[ORM\Index(name: 'idx_kpi_daily_date', columns: ['date'])]
#[ORM\Index(name: 'idx_kpi_daily_hospital_date', columns: ['hospital_id', 'date'])]
#[ORM\HasLifecycleCallbacks]
class KpiDaily
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Hospital $hospital = null;

    #[ORM\Column]
    private int $importsCount = 0;

    #[ORM\Column]
    private int $successfulImportsCount = 0;

    #[ORM\Column]
    private int $recordsTotal = 0;

    #[ORM\Column]
    private int $recordsProcessed = 0;

    #[ORM\Column]
    private int $recordsRejected = 0;

    #[ORM\Column]
    private int $failedImportsCount = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        \DateTimeImmutable $date,
        ?Hospital $hospital,
        int $importsCount,
        int $successfulImportsCount,
        int $recordsTotal,
        int $recordsProcessed,
        int $recordsRejected,
        int $failedImportsCount,
    ) {
        $this->date = $date;
        $this->hospital = $hospital;
        $this->importsCount = $importsCount;
        $this->successfulImportsCount = $successfulImportsCount;
        $this->recordsTotal = $recordsTotal;
        $this->recordsProcessed = $recordsProcessed;
        $this->recordsRejected = $recordsRejected;
        $this->failedImportsCount = $failedImportsCount;
        $this->createdAt = new \DateTimeImmutable('now');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getHospital(): ?Hospital
    {
        return $this->hospital;
    }

    public function getImportsCount(): int
    {
        return $this->importsCount;
    }

    public function getSuccessfulImportsCount(): int
    {
        return $this->successfulImportsCount;
    }

    public function getRecordsTotal(): int
    {
        return $this->recordsTotal;
    }

    public function getRecordsProcessed(): int
    {
        return $this->recordsProcessed;
    }

    public function getRecordsRejected(): int
    {
        return $this->recordsRejected;
    }

    public function getFailedImportsCount(): int
    {
        return $this->failedImportsCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function updateMetrics(
        int $importsCount,
        int $successfulImportsCount,
        int $recordsTotal,
        int $recordsProcessed,
        int $recordsRejected,
        int $failedImportsCount,
    ): void {
        $this->importsCount = $importsCount;
        $this->successfulImportsCount = $successfulImportsCount;
        $this->recordsTotal = $recordsTotal;
        $this->recordsProcessed = $recordsProcessed;
        $this->recordsRejected = $recordsRejected;
        $this->failedImportsCount = $failedImportsCount;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now');
    }
}
