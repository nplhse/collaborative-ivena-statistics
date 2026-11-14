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
 * Mapped for the schema only: PlausibilitySnapshotBuilder writes and PlausibilityTableQuery reads the rows with SQL,
 * so the class has no constructor and no accessors.
 *
 * @psalm-suppress UnusedClass
 * @psalm-suppress UnusedProperty
 * @psalm-suppress MissingConstructor
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
}
