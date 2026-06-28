<?php

declare(strict_types=1);

namespace App\Shared\Application\Export;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ExporterRegistry
{
    /**
     * @param iterable<ExporterInterface> $exporters
     */
    public function __construct(
        #[AutowireIterator('app.exporter')]
        private iterable $exporters,
    ) {
    }

    public function get(string $key): ExporterInterface
    {
        foreach ($this->exporters as $exporter) {
            if ($exporter->key() === $key) {
                return $exporter;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown exporter key: %s', $key));
    }
}
