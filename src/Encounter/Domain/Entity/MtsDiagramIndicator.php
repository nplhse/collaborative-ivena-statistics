<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Shared\Infrastructure\Audit\Attribute as Audit;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

/**
 * Definition that an MTS indicator belongs to an MTS diagram (ADR 032). Not a count: which indicators were seen with
 * a diagram in the encounters is read from the encounter rows.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'encounter_mts_diagram_indicator')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_mts_diagram_indicator', fields: ['diagram', 'indicator'])]
#[ORM\Index(name: 'idx_encounter_mts_diagram_indicator_indicator', fields: ['indicator'])]
class MtsDiagramIndicator implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MtsDiagram $diagram;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MtsIndicator $indicator;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(?MtsDiagram $diagram = null, ?MtsIndicator $indicator = null)
    {
        $this->diagram = $diagram;
        $this->indicator = $indicator;
        $this->createdAt = Clock::get()->now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDiagram(): ?MtsDiagram
    {
        return $this->diagram;
    }

    public function setDiagram(?MtsDiagram $diagram): void
    {
        $this->diagram = $diagram;
    }

    public function getIndicator(): ?MtsIndicator
    {
        return $this->indicator;
    }

    public function setIndicator(?MtsIndicator $indicator): void
    {
        $this->indicator = $indicator;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[\Override]
    public function __toString(): string
    {
        return \sprintf('%s – %s', $this->diagram?->getCode() ?? '?', $this->indicator?->getCode() ?? '?');
    }
}
