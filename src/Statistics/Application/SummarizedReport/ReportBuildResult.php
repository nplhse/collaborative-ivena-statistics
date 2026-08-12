<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport;

final readonly class ReportBuildResult
{
    public function __construct(
        public string $template,
        public object $viewModel,
    ) {
    }
}
