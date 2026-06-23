<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\Export;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class TabularExporterRegistry
{
    /**
     * @param iterable<TabularExporterInterface> $exporters
     */
    public function __construct(
        #[AutowireIterator('statistics.tabular_exporter')]
        private iterable $exporters,
    ) {
    }

    public function get(string $format): TabularExporterInterface
    {
        foreach ($this->exporters as $exporter) {
            if ($exporter->supports($format)) {
                return $exporter;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unsupported tabular export format: %s', $format));
    }
}
