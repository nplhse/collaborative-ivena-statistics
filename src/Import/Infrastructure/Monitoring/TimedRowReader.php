<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Monitoring;

use App\Import\Application\Contracts\RowReaderInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Adds the time spent advancing the inner reader (file I/O, CSV parsing, charset conversion) to the run's read phase.
 * Created per run by ImportRunTelemetry, never a service.
 */
#[Exclude]
final readonly class TimedRowReader implements RowReaderInterface
{
    public function __construct(
        private RowReaderInterface $inner,
        private ImportRunTelemetry $telemetry,
    ) {
    }

    #[\Override]
    public function rows(): iterable
    {
        return $this->timed($this->inner->rows());
    }

    #[\Override]
    public function header(): ?array
    {
        $started = hrtime(true);
        $header = $this->inner->header();
        $this->telemetry->addReadTime(hrtime(true) - $started);

        return $header;
    }

    #[\Override]
    public function rowsAssoc(): iterable
    {
        return $this->timed($this->inner->rowsAssoc());
    }

    /**
     * @template T
     *
     * @param iterable<T> $rows
     *
     * @return \Generator<int, T>
     */
    private function timed(iterable $rows): \Generator
    {
        $started = hrtime(true);
        $iterator = $rows instanceof \Iterator ? $rows : (static fn (): \Generator => yield from $rows)();
        $iterator->rewind();
        $this->telemetry->addReadTime(hrtime(true) - $started);

        while ($iterator->valid()) {
            yield $iterator->current();

            $started = hrtime(true);
            $iterator->next();
            $this->telemetry->addReadTime(hrtime(true) - $started);
        }
    }
}
