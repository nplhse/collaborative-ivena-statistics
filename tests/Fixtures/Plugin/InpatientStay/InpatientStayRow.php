<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Plugin\InpatientStay;

use App\Encounter\Contract\Import\EncounterRecordRow;

final readonly class InpatientStayRow implements EncounterRecordRow
{
    public function __construct(
        public string $fingerprint,
        public string $caseKey,
        public \DateTimeImmutable $admittedAt,
        public ?string $dischargeDiagnosis,
        public ?\DateTimeImmutable $dischargedAt,
    ) {
    }

    #[\Override]
    public function coverageAt(): \DateTimeImmutable
    {
        return $this->admittedAt;
    }
}
