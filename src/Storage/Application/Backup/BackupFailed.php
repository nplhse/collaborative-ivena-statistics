<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * An expected backup failure with an operator-facing message (ADR 038, "Errors").
 *
 * Messages never contain credentials or connection strings. The step is set by BackupCreator.
 */
final class BackupFailed extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $hint = null,
        public readonly ?BackupStep $step = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function inStep(BackupStep $step): self
    {
        return $this->step instanceof BackupStep ? $this : new self($this->getMessage(), $this->hint, $step, $this);
    }
}
