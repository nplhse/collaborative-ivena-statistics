<?php

declare(strict_types=1);

namespace App\Encounter\Application\CaseCard;

/**
 * One timestamp on the hospital course timeline. `minutesFromAdmission` is relative to the ED
 * admission (negative before it), null without ED admission.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the case card template.
 */
final readonly class TimelineEvent
{
    public function __construct(
        public string $key,
        public \DateTimeImmutable $at,
        public ?int $minutesFromAdmission,
    ) {
    }
}
