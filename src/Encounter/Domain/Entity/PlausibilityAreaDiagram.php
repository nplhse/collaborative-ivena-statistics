<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Shared\Infrastructure\Audit\Attribute as Audit;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A curated rule of the plausibility table (ADR 033): an MTS diagram plausible with the codes of a problem area, with
 * its points (0 … +3). A diagram with rules cannot be merged in the MTS catalog (ADR 032).
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'encounter_plausibility_area_diagram')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_plausibility_area_diagram', fields: ['area', 'diagram'])]
#[ORM\Index(name: 'idx_encounter_plausibility_area_diagram_diagram', fields: ['diagram'])]
class PlausibilityAreaDiagram implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'diagrams')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlausibilityProblemArea $area;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private MtsDiagram $diagram;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $points;

    public function __construct(PlausibilityProblemArea $area, MtsDiagram $diagram, int $points)
    {
        $this->area = $area;
        $this->diagram = $diagram;
        $this->setPoints($points);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArea(): PlausibilityProblemArea
    {
        return $this->area;
    }

    public function getDiagram(): MtsDiagram
    {
        return $this->diagram;
    }

    public function getPoints(): int
    {
        return $this->points;
    }

    public function setPoints(int $points): void
    {
        PlausibilityCell::assertPoints($points);
        $this->points = $points;
    }

    #[\Override]
    public function __toString(): string
    {
        return \sprintf('%s – %s (+%d)', $this->area->getCode(), $this->diagram->getCode(), $this->points);
    }
}
