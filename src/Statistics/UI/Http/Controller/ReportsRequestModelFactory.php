<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Report\ReportLimitPolicy;

final class ReportsRequestModelFactory
{
    public function __construct(
        private readonly ReportLimitPolicy $reportLimitPolicy,
    ) {
    }

    public function fromQuery(array $query): ReportsRequestModel
    {
        $reportKey = isset($query['report']) ? (string) $query['report'] : '';

        return new ReportsRequestModel(
            $reportKey,
            $this->reportLimitPolicy->normalize($query['limit'] ?? null),
        );
    }
}
