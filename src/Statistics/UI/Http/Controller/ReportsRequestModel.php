<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final readonly class ReportsRequestModel
{
    public function __construct(
        public string $reportKey,
        public int $limit,
    ) {
    }
}
