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
     */
    public function __construct(
        public array $departmentIds = [],
        public array $specialityIds = [],
        public array $careLevels = [],
        public array $reasons = [],
        public array $closureUnits = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }
}
