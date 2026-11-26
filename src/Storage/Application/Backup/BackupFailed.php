<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * An expected backup or restore failure with an operator-facing message (ADR 038, "Errors").
 *
 * Messages never contain credentials or connection strings. The step is set by BackupCreator or BackupRestorer;
 * a failed restore also states what it left behind (whether database and files were changed).
 */
final class BackupFailed extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $hint = null,
        public readonly BackupStep|RestoreStep|null $step = null,
        ?\Throwable $previous = null,
        public readonly ?string $state = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function inStep(BackupStep|RestoreStep $step): self
    {
        return null !== $this->step ? $this : new self($this->getMessage(), $this->hint, $step, $this, $this->state);
    }

    public function withState(string $state): self
    {
        return new self($this->getMessage(), $this->hint, $this->step, $this, $state);
    }
}
