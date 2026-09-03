<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;

final readonly class TopListComparisonRow
{
    public function __construct(
        public string $identity,
        public string $label,
        public ?int $rankA,
        public ?int $countA,
        public ?float $shareA,
        public ?int $rankB,
        public ?int $countB,
        public ?float $shareB,
        public ?int $deltaCount,
        public ?float $deltaShare,
        public ?int $rankMovement,
        public bool $onlyInA,
        public bool $onlyInB,
        public ?int $entityId = null,
        public ?string $publicId = null,
        public ?StatisticWidgetNavigationTarget $labelTarget = null,
    ) {
    }

    public function withLabelTarget(?StatisticWidgetNavigationTarget $labelTarget): self
    {
        return new self(
            $this->identity,
            $this->label,
            $this->rankA,
            $this->countA,
            $this->shareA,
            $this->rankB,
            $this->countB,
            $this->shareB,
            $this->deltaCount,
            $this->deltaShare,
            $this->rankMovement,
            $this->onlyInA,
            $this->onlyInB,
            $this->entityId,
            $this->publicId,
            $labelTarget,
        );
    }
}
