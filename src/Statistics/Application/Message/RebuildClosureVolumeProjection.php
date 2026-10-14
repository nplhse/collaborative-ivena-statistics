<?php

declare(strict_types=1);

namespace App\Statistics\Application\Message;

/**
 * Consumes open closure rebuild requests. Beta projections have no method version.
 * The hourly check sets $waitForLock to false so it never queues behind a running rebuild.
 */
final readonly class RebuildClosureVolumeProjection
{
    public function __construct(public bool $waitForLock = true)
    {
    }
}
