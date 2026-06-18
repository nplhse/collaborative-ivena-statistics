<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationCompare\DTO;

use App\Statistics\Application\IndicationDashboard\IndicationSubjectType;

final readonly class IndicationCompareSubjectPair
{
    public function __construct(
        public IndicationSubjectType $typeA,
        public int $idA,
        public IndicationSubjectType $typeB,
        public int $idB,
    ) {
    }

    public function isSameSubject(): bool
    {
        return $this->typeA === $this->typeB && $this->idA === $this->idB;
    }
}
