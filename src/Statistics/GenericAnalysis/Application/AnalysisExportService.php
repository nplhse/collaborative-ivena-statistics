<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Application\Contract\AnalysisExportServiceInterface;
use App\Statistics\GenericAnalysis\Application\Export\ExportFilenameFactory;
use App\Statistics\GenericAnalysis\Application\Export\TabularExportDocument;
use App\Statistics\GenericAnalysis\Application\Export\TabularExporterRegistry;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class AnalysisExportService implements AnalysisExportServiceInterface
{
    public function __construct(
        private TabularExporterRegistry $tabularExporterRegistry,
        private ExportFilenameFactory $filenameFactory,
    ) {
    }

    #[\Override]
    public function supportedFormats(): array
    {
        return ['csv'];
    }

    #[\Override]
    public function exportTable(TabularExportDocument $document, string $format, string $title): StreamedResponse
    {
        $exporter = $this->tabularExporterRegistry->get($format);
        $filename = $this->filenameFactory->create($title, $exporter->fileExtension());

        return new StreamedResponse(
            function () use ($exporter, $document): void {
                $stream = fopen('php://output', 'w');
                if (false === $stream) {
                    throw new \RuntimeException('Cannot open output stream for tabular export.');
                }

                try {
                    $exporter->export($document, $stream);
                } finally {
                    fclose($stream);
                }
            },
            StreamedResponse::HTTP_OK,
            [
                'Content-Type' => $exporter->contentType(),
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ],
        );
    }
}
