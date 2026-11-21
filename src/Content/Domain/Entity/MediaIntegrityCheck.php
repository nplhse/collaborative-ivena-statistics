<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Result of one run of the media integrity check (ADR 039). The health check reads the latest row; older rows are
 * kept for 90 days to see when a problem began.
 */
#[ORM\Entity]
#[ORM\Table(name: 'media_integrity_check')]
#[ORM\Index(name: 'idx_media_integrity_check_checked_at', columns: ['checked_at'])]
class MediaIntegrityCheck
{
    /** Findings stored with a run; the counts are always complete. */
    public const int MAX_FINDINGS = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param list<array{kind: string, key: string}> $findings missing and damaged files, the first {@see MAX_FINDINGS}
     */
    public function __construct(
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $checkedAt,
        #[ORM\Column(name: 'full_check')]
        private bool $full,
        #[ORM\Column]
        private int $files,
        #[ORM\Column(type: Types::BIGINT)]
        private int|string $bytes,
        #[ORM\Column]
        private int $hashed,
        #[ORM\Column]
        private int $missing,
        #[ORM\Column]
        private int $damaged,
        #[ORM\Column]
        private int $orphansDeleted,
        #[ORM\Column]
        private int $orphansKept,
        #[ORM\Column]
        private int $unexpected,
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $deletionBlockedReason,
        #[ORM\Column(type: Types::JSON)]
        private array $findings,
    ) {
        $this->findings = \array_slice($findings, 0, self::MAX_FINDINGS);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function isFull(): bool
    {
        return $this->full;
    }

    public function getFiles(): int
    {
        return $this->files;
    }

    public function getBytes(): int
    {
        return (int) $this->bytes;
    }

    public function getHashed(): int
    {
        return $this->hashed;
    }

    public function getMissing(): int
    {
        return $this->missing;
    }

    public function getDamaged(): int
    {
        return $this->damaged;
    }

    public function getOrphansDeleted(): int
    {
        return $this->orphansDeleted;
    }

    public function getOrphansKept(): int
    {
        return $this->orphansKept;
    }

    public function getUnexpected(): int
    {
        return $this->unexpected;
    }

    public function getDeletionBlockedReason(): ?string
    {
        return $this->deletionBlockedReason;
    }

    /**
     * @return list<array{kind: string, key: string}>
     */
    public function getFindings(): array
    {
        return $this->findings;
    }

    /** Files that are missing or damaged; orphans and unexpected files are not problems of the media library. */
    public function hasProblems(): bool
    {
        return $this->missing > 0 || $this->damaged > 0;
    }
}
