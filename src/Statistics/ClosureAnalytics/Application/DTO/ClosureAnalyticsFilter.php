<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureReason;

final readonly class ClosureAnalyticsFilter
{
    /**
     * @param list<int>                        $departmentIds
     * @param list<int>                        $specialityIds
     * @param list<value-of<ClosureCareLevel>> $careLevels
     * @param list<value-of<ClosureReason>>    $reasons
     * @param list<string>                     $closureUnits
     * @param list<value-of<ClosureEventType>> $eventTypes
     * @param list<int>                        $hospitalIds
     */
    public function __construct(
        public array $departmentIds = [],
        public array $specialityIds = [],
        public array $careLevels = [],
        public array $reasons = [],
        public array $closureUnits = [],
        public ?string $fromDate = null,
        public ?string $toDate = null,
        public array $eventTypes = [],
        public array $hospitalIds = [],
        public bool $hospitalIdsSubmitted = false,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function periodFrom(): ?\DateTimeImmutable
    {
        return $this->startOfDay($this->fromDate);
    }

    public function periodToExclusive(): ?\DateTimeImmutable
    {
        $to = $this->startOfDay($this->toDate);

        return $to instanceof \DateTimeImmutable ? $to->modify('+1 day') : null;
    }

    private function startOfDay(?string $date): ?\DateTimeImmutable
    {
        if (null === $date || '' === $date) {
            return null;
        }

        return new \DateTimeImmutable($date.' 00:00:00');
    }
}
