<?php

declare(strict_types=1);

namespace App\Import\Domain\Entity;

use App\Import\Domain\Enum\ImportMilestoneType;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A durable import milestone of a user (ADR 016, "First successful import"). Recorded once, in the
 * transaction of the run that reached it; later runs, re-imports or deleted imports never record it again.
 * Rows are inserted with DBAL (ON CONFLICT DO NOTHING); the entity is used for reading.
 *
 * @psalm-suppress ClassMustBeFinal
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'user_import_milestone')]
#[ORM\UniqueConstraint(name: 'uniq_user_import_milestone', columns: ['user_id', 'milestone_type'])]
#[ORM\Index(name: 'idx_user_import_milestone_achieved_at', columns: ['achieved_at'])]
#[ORM\Index(name: 'idx_user_import_milestone_run', columns: ['import_run_id'])]
class UserImportMilestone
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, enumType: ImportMilestoneType::class)]
    private ImportMilestoneType $milestoneType;

    #[ORM\ManyToOne(targetEntity: ImportRun::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ImportRun $importRun;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $achievedAt;

    public function __construct(User $user, ImportMilestoneType $milestoneType, \DateTimeImmutable $achievedAt, ?ImportRun $importRun = null)
    {
        $this->user = $user;
        $this->milestoneType = $milestoneType;
        $this->achievedAt = $achievedAt;
        $this->importRun = $importRun;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getMilestoneType(): ImportMilestoneType
    {
        return $this->milestoneType;
    }

    public function getImportRun(): ?ImportRun
    {
        return $this->importRun;
    }

    public function getAchievedAt(): \DateTimeImmutable
    {
        return $this->achievedAt;
    }
}
