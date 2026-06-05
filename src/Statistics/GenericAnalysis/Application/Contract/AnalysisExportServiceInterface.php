<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\Contract;

/**
 * Export formats for analysis views (PNG, SVG, CSV, PDF) — implementation deferred.
 */
interface AnalysisExportServiceInterface
{
    /**
     * @return list<string>
     */
    public function supportedFormats(): array;
}
