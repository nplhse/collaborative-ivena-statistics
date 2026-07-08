<?php

declare(strict_types=1);

namespace App\Shared\Application\PublicId;

final class PublicIdBackfillInterruptedException extends \RuntimeException
{
    public function __construct(
        private readonly int $signal,
    ) {
        parent::__construct(sprintf('Public ID backfill interrupted by signal %d.', $signal));
    }

    public function getSignal(): int
    {
        return $this->signal;
    }
}
