<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard;

final readonly class IndicationSubject
{
    public function __construct(
        public IndicationSubjectType $type,
        public int $id,
        public string $label,
        /** @var list<int> */
        public array $indicationIds,
    ) {
    }
}
