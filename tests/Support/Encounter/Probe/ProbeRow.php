<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter\Probe;

use App\Encounter\Contract\Import\EncounterRecordRow;

final readonly class ProbeRow implements EncounterRecordRow
{
    public function __construct(
        private int $line,
        public string $fingerprint,
        private ?\DateTimeImmutable $recordedAt,
    ) {
    }

    public function line(): int
    {
        return $this->line;
    }

    #[\Override]
    public function coverageAt(): ?\DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
