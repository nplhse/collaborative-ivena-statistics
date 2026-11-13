<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Encounter\Domain\Enum\PlausibilitySnapshotOrigin;
use App\Encounter\Domain\Enum\PlausibilitySnapshotStatus;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use Doctrine\ORM\Mapping as ORM;

/**
 * An immutable set of plausibility cells and PZC → problem-area memberships (ADR 033). The matcher (#1154) reads the
 * active snapshot only; without one the bonus is 0 everywhere.
 *
 * Status goes `draft` → `active` → `retired`; a partial unique index allows one active snapshot. Cells and memberships
 * change only while the snapshot is a draft. The learning job (#1155) adds what a learned snapshot was learned from
 * and its report.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'encounter_plausibility_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_plausibility_snapshot_active', fields: ['status'], options: ['where' => "((status)::text = 'active'::text)"])]
class PlausibilitySnapshot implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: PlausibilitySnapshotStatus::class)]
    private PlausibilitySnapshotStatus $status = PlausibilitySnapshotStatus::Draft;

    #[ORM\Column(length: 16, enumType: PlausibilitySnapshotOrigin::class)]
    private PlausibilitySnapshotOrigin $origin;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    public function __construct(PlausibilitySnapshotOrigin $origin, \DateTimeImmutable $createdAt)
    {
        $this->origin = $origin;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): PlausibilitySnapshotStatus
    {
        return $this->status;
    }

    public function isDraft(): bool
    {
        return PlausibilitySnapshotStatus::Draft === $this->status;
    }

    public function isActive(): bool
    {
        return PlausibilitySnapshotStatus::Active === $this->status;
    }

    public function getOrigin(): PlausibilitySnapshotOrigin
    {
        return $this->origin;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getRetiredAt(): ?\DateTimeImmutable
    {
        return $this->retiredAt;
    }

    public function activate(\DateTimeImmutable $now): void
    {
        if (!$this->isDraft()) {
            throw new \LogicException('Only a draft plausibility snapshot can be activated.');
        }

        $this->status = PlausibilitySnapshotStatus::Active;
        $this->activatedAt = $now;
    }

    public function retire(\DateTimeImmutable $now): void
    {
        if (!$this->isActive()) {
            throw new \LogicException('Only the active plausibility snapshot can be retired.');
        }

        $this->status = PlausibilitySnapshotStatus::Retired;
        $this->retiredAt = $now;
    }

    #[\Override]
    public function __toString(): string
    {
        return \sprintf('#%s (%s, %s)', $this->id ?? '?', $this->origin->value, $this->status->value);
    }
}
