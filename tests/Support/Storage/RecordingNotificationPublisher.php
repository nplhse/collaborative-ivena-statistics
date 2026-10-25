<?php

declare(strict_types=1);

namespace App\Tests\Support\Storage;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationRequest;

/**
 * Publisher spy that keeps one notification per deduplication key, like the real publisher.
 */
final class RecordingNotificationPublisher implements NotificationPublisherInterface
{
    /** @var list<NotificationRequest> */
    public array $requests = [];

    /** @var array<string, NotificationRequest> */
    public array $published = [];

    #[\Override]
    public function publish(NotificationRequest $request): void
    {
        $this->requests[] = $request;
        $this->published[$request->deduplicationKey] ??= $request;
    }
}
