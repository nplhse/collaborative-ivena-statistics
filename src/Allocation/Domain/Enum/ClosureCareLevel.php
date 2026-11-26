<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

/**
 * Care level of a closure interval.
 *
 * The three SK values map onto {@see AllocationUrgency}. "Sonstige" stays on this
 * enum and does not become an SK, but it closes every SK (#571).
 */
enum ClosureCareLevel: string
{
    case EMERGENCY = 'emergency';

    case INPATIENT = 'inpatient';

    case OUTPATIENT = 'outpatient';

    case OTHER = 'other';

    public function toAllocationUrgency(): ?AllocationUrgency
    {
        return match ($this) {
            self::EMERGENCY => AllocationUrgency::EMERGENCY,
            self::INPATIENT => AllocationUrgency::INPATIENT,
            self::OUTPATIENT => AllocationUrgency::OUTPATIENT,
            self::OTHER => null,
        };
    }

    /**
     * Urgencies an interval with this care level closes: its own SK, or SK1–SK3 for "Sonstige".
     *
     * @return non-empty-list<AllocationUrgency>
     */
    public function closesUrgencies(): array
    {
        $urgency = $this->toAllocationUrgency();

        return $urgency instanceof AllocationUrgency ? [$urgency] : AllocationUrgency::cases();
    }

    public function skLabel(): ?string
    {
        return $this->toAllocationUrgency()?->skLabel();
    }
}
