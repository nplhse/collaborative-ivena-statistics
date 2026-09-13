<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application\DTO;

use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentNamedCard;

final readonly class ClosedDepartmentNamedCardView
{
    /**
     * @param list<ClosedDepartmentNamedRow> $rows
     */
    public function __construct(
        public ClosedDepartmentNamedCard $card,
        public array $rows,
        public ?string $topListUrl = null,
    ) {
    }

    public function withTopListUrl(?string $topListUrl): self
    {
        return new self($this->card, $this->rows, $topListUrl);
    }

    public function titleKey(): string
    {
        return $this->card->titleKey();
    }

    public function testId(): string
    {
        return $this->card->testId();
    }
}
