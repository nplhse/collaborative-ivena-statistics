<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Assignment;

/**
 * What a record knows to find its encounter: always the hospital and key generation of its import,
 * optionally the allocation it names, its case pseudonym and its clinical time.
 */
final readonly class AssignmentRequest
{
    /**
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function __construct(
        public int $hospitalId,
        public int $keyGeneration,
        public ?int $allocationId = null,
        public ?string $caseKey = null,
        public ?\DateTimeImmutable $at = null,
    ) {
    }
}
