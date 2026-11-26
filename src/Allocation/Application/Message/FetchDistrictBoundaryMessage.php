<?php

declare(strict_types=1);

namespace App\Allocation\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Fetches the boundary of one district. Only the attempt that is still current may write its result (ADR 041).
 */
#[AsMessage('async_priority_low')]
final readonly class FetchDistrictBoundaryMessage
{
    public function __construct(
        public int $districtId,
        public string $attemptId,
    ) {
    }
}
