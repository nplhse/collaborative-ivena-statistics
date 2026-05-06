<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Report\ReportLimitPolicy;

final readonly class ReportsRequestModelFactory
{
    public function __construct(
        private ReportLimitPolicy $reportLimitPolicy,
    ) {
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public function fromQuery(array $query): ReportsRequestModel
    {
        $reportKey = isset($query['report']) ? (string) $query['report'] : '';

        return new ReportsRequestModel(
            $reportKey,
            $this->reportLimitPolicy->normalize($query['limit'] ?? null),
        );
    }
}
