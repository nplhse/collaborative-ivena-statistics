<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\MtsCatalogSource;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

/**
 * Common fields of an MTS diagram or indicator (ADR 032).
 *
 * The code is derived once from the German name when the entry is created and never changes afterwards, even when
 * the German name is corrected: encounters store it as a plain code. Status `merged` is set and cleared only by
 * merging and undoing (MtsCatalogCuration), never by editing.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class MtsCatalogItem implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $germanName;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $englishName = null;

    #[ORM\Column(length: 16, enumType: MtsCatalogSource::class)]
    private MtsCatalogSource $source;

    #[ORM\Column(length: 16, enumType: MtsCatalogStatus::class)]
    private MtsCatalogStatus $status;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * An empty code is allowed only for a new entry whose code is assigned before it is stored (assignCode()).
     */
    public function __construct(
        string $code,
        string $germanName,
        MtsCatalogSource $source,
        MtsCatalogStatus $status,
        ?\DateTimeImmutable $firstSeenAt = null,
    ) {
        if (MtsCatalogStatus::Merged === $status) {
            throw new \InvalidArgumentException('A new MTS catalog entry cannot be merged.');
        }

        $this->code = $code;
        $this->germanName = $germanName;
        $this->source = $source;
        $this->status = $status;
        $this->firstSeenAt = $firstSeenAt;
        $this->createdAt = Clock::get()->now();
    }

    abstract public function getKind(): MtsCatalogKind;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Sets the code of a new entry once; the code of a stored entry never changes.
     */
    public function assignCode(string $code): void
    {
        if (null !== $this->id || ('' !== $this->code && $code !== $this->code)) {
            throw new \LogicException(\sprintf('The code of the MTS catalog entry "%s" cannot change.', $this->code));
        }

        $this->code = $code;
    }

    public function getGermanName(): string
    {
        return $this->germanName;
    }

    public function setGermanName(?string $germanName): void
    {
        $this->germanName = trim((string) $germanName);
    }

    public function getEnglishName(): ?string
    {
        return $this->englishName;
    }

    public function setEnglishName(?string $englishName): void
    {
        $englishName = null === $englishName ? null : trim($englishName);
        $this->englishName = '' === $englishName ? null : $englishName;
    }

    public function getSource(): MtsCatalogSource
    {
        return $this->source;
    }

    public function setSource(MtsCatalogSource $source): void
    {
        $this->source = $source;
    }

    public function getStatus(): MtsCatalogStatus
    {
        return $this->status;
    }

    /**
     * Switches between `curated` and `provisional`; `merged` is reached only through markMerged().
     */
    public function setStatus(MtsCatalogStatus $status): void
    {
        if ($status === $this->status) {
            return;
        }

        if (MtsCatalogStatus::Merged === $status || MtsCatalogStatus::Merged === $this->status) {
            throw new \LogicException('Only merging and undoing a merge change the status "merged".');
        }

        $this->status = $status;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function markMerged(): void
    {
        if (MtsCatalogStatus::Provisional !== $this->status) {
            throw new \LogicException('Only a provisional MTS catalog entry can be merged.');
        }

        $this->status = MtsCatalogStatus::Merged;
    }

    public function undoMerge(): void
    {
        if (MtsCatalogStatus::Merged !== $this->status) {
            throw new \LogicException('The MTS catalog entry is not merged.');
        }

        $this->status = MtsCatalogStatus::Provisional;
    }

    public function getFirstSeenAt(): ?\DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = Clock::get()->now();
    }

    #[\Override]
    public function __toString(): string
    {
        return \sprintf('%s (%s)', $this->germanName, $this->code);
    }
}
