<?php

declare(strict_types=1);

namespace App\Allocation\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Rebuilds the cached My Hospitals snapshot of one hospital. Idempotent: warming twice only reads twice.
 */
#[AsMessage('async_priority_low')]
final readonly class WarmHospitalDataSnapshot
{
    public function __construct(
        public int $hospitalId,
    ) {
    }
}
