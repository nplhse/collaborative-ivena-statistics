<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application\DTO;

use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentNamedCard;

final readonly class ClosedDepartmentNamedCardFrame
{
    public function __construct(
        public ClosedDepartmentNamedCard $card,
        public string $url,
        public string $frameId,
        public string $testId,
    ) {
    }

    public static function from(ClosedDepartmentNamedCard $card, string $url): self
    {
        return new self($card, $url, $card->frameId(), $card->testId());
    }
}
