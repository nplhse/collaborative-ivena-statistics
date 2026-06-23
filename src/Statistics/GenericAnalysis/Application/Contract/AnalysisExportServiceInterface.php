<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\Contract;

use App\Statistics\GenericAnalysis\Application\Export\TabularExportDocument;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface AnalysisExportServiceInterface
{
    /**
     * @return list<string>
     */
    public function supportedFormats(): array;

    public function exportTable(TabularExportDocument $document, string $format, string $title): StreamedResponse;
}
