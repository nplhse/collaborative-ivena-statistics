<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The problem area of a PZC in one snapshot (ADR 033), copied from the curated areas when the snapshot is built, so the
 * fallback lookup of an active snapshot never changes when the areas are edited. Not audited: written in bulk while
 * the snapshot is a draft.
 *
 * @psalm-suppress UnusedClass Mapped for the schema; written and read in bulk with SQL (PlausibilitySnapshotBuilder,
 *     PlausibilityTableQuery).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'encounter_plausibility_snapshot_code')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_plausibility_snapshot_code', fields: ['snapshot', 'pzc'])]
class PlausibilitySnapshotCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlausibilitySnapshot $snapshot;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $pzc;

    #[ORM\Column(length: 64)]
    private string $areaCode;

    public function __construct(PlausibilitySnapshot $snapshot, int $pzc, string $areaCode)
    {
        $this->snapshot = $snapshot;
        $this->pzc = $pzc;
        $this->areaCode = $areaCode;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSnapshot(): PlausibilitySnapshot
    {
        return $this->snapshot;
    }

    public function getPzc(): int
    {
        return $this->pzc;
    }

    public function getAreaCode(): string
    {
        return $this->areaCode;
    }
}
