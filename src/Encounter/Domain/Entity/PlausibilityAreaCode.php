<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Shared\Infrastructure\Audit\Attribute as Audit;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A PZC code of a curated problem area (ADR 033). The unique constraint on the code keeps each PZC in at most one area.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'encounter_plausibility_area_code')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_plausibility_area_code_pzc', fields: ['pzc'])]
class PlausibilityAreaCode
{
    /** @psalm-suppress UnusedProperty Identifier set by Doctrine; codes are found by PZC, never by id. */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'codes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlausibilityProblemArea $area;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $pzc;

    public function __construct(PlausibilityProblemArea $area, int $pzc)
    {
        if ($pzc < 0 || $pzc > 999) {
            throw new \InvalidArgumentException(\sprintf('A PZC has three digits, got %d.', $pzc));
        }

        $this->area = $area;
        $this->pzc = $pzc;
    }

    public function getArea(): PlausibilityProblemArea
    {
        return $this->area;
    }

    public function getPzc(): int
    {
        return $this->pzc;
    }
}
