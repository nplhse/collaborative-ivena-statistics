<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Application\Contract\AnalysisExportServiceInterface;

final readonly class AnalysisExportService implements AnalysisExportServiceInterface
{
    #[\Override]
    public function supportedFormats(): array
    {
        return ['png', 'svg', 'csv', 'pdf'];
    }
}
