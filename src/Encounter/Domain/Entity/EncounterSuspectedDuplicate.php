<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Encounter\Domain\Enum\AnchorSource;
use App\Import\Domain\Entity\Import;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A visit that an import inserted although the hospital already had a visit with the same case key in
 * the same key generation and a time anchor within 15 minutes (ADR 028, #1045). Reported, never merged;
 * it measures the stability of the fingerprint rule in operation.
 *
 * Both visits reference `encounter` with ON DELETE CASCADE, so the report goes with either of them
 * (deletion or reprocessing of one of the imports). It stores no case key or timestamp.
 */
#[ORM\Entity]
#[ORM\Table(name: 'encounter_suspected_duplicate')]
#[ORM\Index(name: 'idx_encounter_suspected_duplicate_import', fields: ['import'])]
class EncounterSuspectedDuplicate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        /** The import that inserted the new visit. */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Import $import,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Encounter $encounter,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Encounter $existingEncounter,
        /** Line of the new visit in the uploaded file (header = line 2). */
        #[ORM\Column(type: Types::INTEGER)]
        private int $lineNumber,
        /** Absolute difference of the two time anchors in minutes (0–15). */
        #[ORM\Column(type: Types::SMALLINT)]
        private int $anchorDifferenceMinutes,
        #[ORM\Column(length: 16, enumType: AnchorSource::class)]
        private AnchorSource $anchorSource,
        #[ORM\Column(length: 16, enumType: AnchorSource::class)]
        private AnchorSource $existingAnchorSource,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getImport(): Import
    {
        return $this->import;
    }

    public function getEncounter(): Encounter
    {
        return $this->encounter;
    }

    public function getExistingEncounter(): Encounter
    {
        return $this->existingEncounter;
    }

    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }

    public function getAnchorDifferenceMinutes(): int
    {
        return $this->anchorDifferenceMinutes;
    }

    public function getAnchorSource(): AnchorSource
    {
        return $this->anchorSource;
    }

    public function getExistingAnchorSource(): AnchorSource
    {
        return $this->existingAnchorSource;
    }
}
