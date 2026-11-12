<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Encounter\Domain\Enum\MtsCategory;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Initial Manchester Triage System assessment (`mts_*`, `pain_score`). Diagram and indicator are
 * codes of the MTS catalog; the catalog itself is finalised with the import (#1048).
 */
#[ORM\Embeddable]
class Triage
{
    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 16, nullable: true, enumType: MtsCategory::class)]
        private ?MtsCategory $category = null,
        #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
        private ?string $diagram = null,
        #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
        private ?string $indicator = null,
        #[ORM\Column(type: Types::SMALLINT, nullable: true)]
        private ?int $painScore = null,
    ) {
        if (null !== $painScore && ($painScore < 0 || $painScore > 10)) {
            throw new \InvalidArgumentException(sprintf('Pain score must be 0–10, got %d.', $painScore));
        }
    }

    public function getCategory(): ?MtsCategory
    {
        return $this->category;
    }

    public function getDiagram(): ?string
    {
        return $this->diagram;
    }

    public function getIndicator(): ?string
    {
        return $this->indicator;
    }

    public function getPainScore(): ?int
    {
        return $this->painScore;
    }
}
