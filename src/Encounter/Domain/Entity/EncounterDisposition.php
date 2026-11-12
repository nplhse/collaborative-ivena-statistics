<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Encounter\Domain\Enum\CareLevel;
use App\Encounter\Domain\Enum\Disposition;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Disposition code and care level of one ED visit. The admitting department references the
 * department catalog and therefore lives on {@see Encounter} (embeddables hold no associations).
 *
 * A care level exists only for an admission (`admitted`) or an external transfer
 * (`transferred_external`); an unmappable ward gives `unknown` (exchange format).
 */
#[ORM\Embeddable]
class EncounterDisposition
{
    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 32, nullable: true, enumType: Disposition::class)]
        private ?Disposition $code = null,
        #[ORM\Column(type: Types::STRING, length: 16, nullable: true, enumType: CareLevel::class)]
        private ?CareLevel $careLevel = null,
    ) {
        if ($careLevel instanceof CareLevel && true !== $code?->carriesCareLevel()) {
            throw new \InvalidArgumentException('A care level is only allowed for an admission or an external transfer.');
        }
    }

    public function getCode(): ?Disposition
    {
        return $this->code;
    }

    public function getCareLevel(): ?CareLevel
    {
        return $this->careLevel;
    }

    public function isAdmission(): bool
    {
        return Disposition::Admitted === $this->code;
    }

    public function isExternalTransfer(): bool
    {
        return Disposition::TransferredExternal === $this->code;
    }
}
