<?php

declare(strict_types=1);

namespace App\Statistics\Application\Message;

final class RecomputeScope
{
    /**
     * @param string $scopeType   hospital|dispatch_area|state|public
     * @param string $scopeId     "all" or numeric-as-string
     * @param string $granularity day|week|month|quarter|year
     * @param string $periodKey   'YYYY-MM-DD' anchor date for the slice
     */
    public function __construct(
        public string $scopeType,
        public string $scopeId,
        public string $granularity,
        public string $periodKey,
    ) {
    }
}
