<?php

declare(strict_types=1);

namespace App\Statistics\UI\Form\Data;

/**
 * Neutral scope/period form data for statistics filter forms (Explorer, etc.).
 */
final class StatisticsScopePeriodFormData
{
    public function __construct(
        public string $scopeGroup = 'public',
        public ?string $scopeDetail = null,
        public string $period = 'all',
        public ?int $periodYear = null,
        public ?int $periodQuarter = null,
        public ?int $periodMonth = null,
    ) {
    }
}
