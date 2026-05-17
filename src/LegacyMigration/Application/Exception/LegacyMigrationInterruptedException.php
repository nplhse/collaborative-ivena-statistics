<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Exception;

final class LegacyMigrationInterruptedException extends \RuntimeException
{
    public function __construct(
        private readonly int $signal,
        string $message = 'Legacy migration was interrupted.',
    ) {
        parent::__construct($message);
    }

    public function getSignal(): int
    {
        return $this->signal;
    }
}
