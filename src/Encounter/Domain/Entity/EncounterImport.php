<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Encounter\Domain\Enum\EncounterFileRejection;
use App\Import\Domain\Entity\Import;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Encounter-specific state of one import of type ENCOUNTER (#1048), next to the generic `Import`.
 *
 * - The upload confirmation (file produced with the tool, no direct identifiers) with user and time.
 * - The key generation of the hospital at upload (ADR 026); every commit of a run checks it again.
 * - What the last run read from the manifest, why it rejected the file, and its row counts.
 * - Whether and when the uploaded file was deleted: at once for a file that may hold direct
 *   identifiers, otherwise by the daily retention job (ADR 025, #1049). Imported encounters stay.
 *
 * Never holds file content or cell values.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'encounter_import')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_import_import', fields: ['import'])]
class EncounterImport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Import $import;

    /** Schema chosen at upload (`ed_visit`); the manifest must name the same schema. */
    #[ORM\Column(name: 'exchange_schema', length: 32)]
    private string $schema;

    #[ORM\Column(type: Types::INTEGER)]
    private int $keyGeneration;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $confirmedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $confirmedAt;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $schemaVersion = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $profile = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $profileVersion = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $toolVersion = null;

    #[ORM\Column(length: 32, nullable: true, enumType: EncounterFileRejection::class)]
    private ?EncounterFileRejection $fileRejection = null;

    /** True once the source file was deleted, at once (direct identifiers) or after the retention period. */
    #[ORM\Column(options: ['default' => false])]
    private bool $sourceFileDeleted = false;

    /** When the source file was deleted; null for deletions before #1049 and while the file is kept. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sourceFileDeletedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $inserted = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $duplicates = 0;

    /** Rows without a time anchor; they get no encounter (ADR 028). */
    #[ORM\Column(options: ['default' => 0])]
    private int $skipped = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $rejected = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $suspectedDuplicates = 0;

    public function __construct(Import $import, string $schema, int $keyGeneration, ?User $confirmedBy, \DateTimeImmutable $confirmedAt)
    {
        if ($keyGeneration < 1) {
            throw new \InvalidArgumentException(sprintf('The key generation starts at 1, got %d.', $keyGeneration));
        }

        $this->import = $import;
        $this->schema = $schema;
        $this->keyGeneration = $keyGeneration;
        $this->confirmedBy = $confirmedBy;
        $this->confirmedAt = $confirmedAt;
    }

    /**
     * Forgets the result of a previous run before the file is processed again.
     */
    public function resetForRun(): void
    {
        $this->schemaVersion = null;
        $this->profile = null;
        $this->profileVersion = null;
        $this->toolVersion = null;
        $this->fileRejection = null;
        $this->inserted = 0;
        $this->duplicates = 0;
        $this->skipped = 0;
        $this->rejected = 0;
        $this->suspectedDuplicates = 0;
    }

    public function recordManifest(?int $schemaVersion, ?string $profile, ?string $profileVersion, ?string $toolVersion): void
    {
        $this->schemaVersion = $schemaVersion;
        $this->profile = $profile;
        $this->profileVersion = $profileVersion;
        $this->toolVersion = $toolVersion;
    }

    public function rejectFile(EncounterFileRejection $reason): void
    {
        $this->fileRejection = $reason;
    }

    /**
     * Records that the uploaded file is gone. The first deletion date is kept.
     */
    public function markSourceFileDeleted(\DateTimeImmutable $deletedAt): void
    {
        if ($this->sourceFileDeleted) {
            return;
        }

        $this->sourceFileDeleted = true;
        $this->sourceFileDeletedAt = $deletedAt;
    }

    public function recordCounts(int $inserted, int $duplicates, int $skipped, int $rejected, int $suspectedDuplicates): void
    {
        $this->fileRejection = null;
        $this->inserted = $inserted;
        $this->duplicates = $duplicates;
        $this->skipped = $skipped;
        $this->rejected = $rejected;
        $this->suspectedDuplicates = $suspectedDuplicates;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getImport(): Import
    {
        return $this->import;
    }

    public function getSchema(): string
    {
        return $this->schema;
    }

    public function getKeyGeneration(): int
    {
        return $this->keyGeneration;
    }

    public function getConfirmedBy(): ?User
    {
        return $this->confirmedBy;
    }

    public function getConfirmedAt(): \DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function getSchemaVersion(): ?int
    {
        return $this->schemaVersion;
    }

    public function getProfile(): ?string
    {
        return $this->profile;
    }

    public function getProfileVersion(): ?string
    {
        return $this->profileVersion;
    }

    public function getToolVersion(): ?string
    {
        return $this->toolVersion;
    }

    public function getFileRejection(): ?EncounterFileRejection
    {
        return $this->fileRejection;
    }

    public function isSourceFileDeleted(): bool
    {
        return $this->sourceFileDeleted;
    }

    public function getSourceFileDeletedAt(): ?\DateTimeImmutable
    {
        return $this->sourceFileDeletedAt;
    }

    public function getInserted(): int
    {
        return $this->inserted;
    }

    public function getDuplicates(): int
    {
        return $this->duplicates;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    public function getRejected(): int
    {
        return $this->rejected;
    }

    public function getSuspectedDuplicates(): int
    {
        return $this->suspectedDuplicates;
    }
}
