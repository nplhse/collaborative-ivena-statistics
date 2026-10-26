<?php

declare(strict_types=1);

namespace App\Shared\Application\Monitoring\Message;

/**
 * Hourly scheduler job: stores the current Messenger queue backlog for its history (ADR 019).
 */
final readonly class SampleMessengerQueuesMessage
{
}
