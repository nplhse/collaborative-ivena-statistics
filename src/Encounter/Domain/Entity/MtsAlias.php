<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

/**
 * Another spelling of an MTS diagram or indicator (ADR 032): the alias code, derived from its label with the catalog
 * rule, resolves to the code of an active entry of the same kind, in one step. The target is a plain code, checked by
 * MtsCatalogCuration, because it can point to either list.
 *
 * An alias whose code is the code of a merged entry stands for that merge; it is removed only by undoing the merge.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'encounter_mts_alias')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_mts_alias_kind_code', fields: ['kind', 'code'])]
#[ORM\Index(name: 'idx_encounter_mts_alias_kind_target', fields: ['kind', 'targetCode'])]
#[ORM\HasLifecycleCallbacks]
class MtsAlias implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: MtsCatalogKind::class)]
    private MtsCatalogKind $kind;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 64)]
    private string $targetCode;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(MtsCatalogKind $kind, string $code, string $label, string $targetCode)
    {
        $this->kind = $kind;
        $this->code = $code;
        $this->label = $label;
        $this->targetCode = $targetCode;
        $this->createdAt = Clock::get()->now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): MtsCatalogKind
    {
        return $this->kind;
    }

    public function setKind(MtsCatalogKind $kind): void
    {
        $this->assertNew();
        $this->kind = $kind;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Sets the code of a new alias once; the code of a stored alias never changes.
     */
    public function assignCode(string $code): void
    {
        $this->assertNew();
        $this->code = $code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = trim((string) $label);
    }

    public function getTargetCode(): string
    {
        return $this->targetCode;
    }

    public function setTargetCode(?string $targetCode): void
    {
        $this->targetCode = (string) $targetCode;
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
        return \sprintf('%s → %s', $this->code, $this->targetCode);
    }

    private function assertNew(): void
    {
        if (null !== $this->id) {
            throw new \LogicException(\sprintf('Kind and code of the MTS alias "%s" cannot change.', $this->code));
        }
    }
}
