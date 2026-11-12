<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Encounter\Domain\Enum\MtsCategory;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Second Manchester Triage System assessment (`retriage_*`), if one was documented.
 */
#[ORM\Embeddable]
class Retriage
{
    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 16, nullable: true, enumType: MtsCategory::class)]
        private ?MtsCategory $category = null,
        #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
        private ?string $diagram = null,
        #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
        private ?string $indicator = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $at = null,
    ) {
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

    public function getAt(): ?\DateTimeImmutable
    {
        return $this->at;
    }
}
