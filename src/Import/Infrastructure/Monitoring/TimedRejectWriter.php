<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Monitoring;

use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Domain\Entity\Import;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Adds the time spent writing rejected rows to the run's persist phase.
 * Created per run by ImportRunTelemetry; excluded from the container, which would otherwise register it
 * as one more tagged reject writer.
 */
#[Exclude]
final readonly class TimedRejectWriter implements RejectWriterInterface
{
    public function __construct(
        private RejectWriterInterface $inner,
        private ImportRunTelemetry $telemetry,
    ) {
    }

    #[\Override]
    public function start(Import $import): void
    {
        $this->inner->start($import);
    }

    #[\Override]
    public function write(array $row, array $messages, ?int $line = null): void
    {
        $started = hrtime(true);
        $this->inner->write($row, $messages, $line);
        $this->telemetry->addPersistTime(hrtime(true) - $started, false);
    }

    #[\Override]
    public function close(): void
    {
        $started = hrtime(true);
        $this->inner->close();
        $this->telemetry->addPersistTime(hrtime(true) - $started, false);
    }

    #[\Override]
    public function getCount(): int
    {
        return $this->inner->getCount();
    }

    #[\Override]
    public function getPath(): ?string
    {
        return $this->inner->getPath();
    }

    #[\Override]
    public function getType(): string
    {
        return $this->inner->getType();
    }
}
