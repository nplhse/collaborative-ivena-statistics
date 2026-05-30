<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Analysis\Export;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class RejectAnalysisExporterRegistry
{
    /**
     * @param iterable<RejectAnalysisExporterInterface> $exporters
     */
    public function __construct(
        #[AutowireIterator('import.reject_analysis_exporter')]
        private iterable $exporters,
    ) {
    }

    public function get(string $format): RejectAnalysisExporterInterface
    {
        foreach ($this->exporters as $exporter) {
            if ($exporter->supports($format)) {
                return $exporter;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unsupported export format: %s', $format));
    }
}
