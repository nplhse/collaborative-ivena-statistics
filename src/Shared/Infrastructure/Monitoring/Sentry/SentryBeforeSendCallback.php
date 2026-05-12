<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Sentry;

use Sentry\Event;

/**
 * @psalm-suppress UnusedClass
 */
final readonly class SentryBeforeSendCallback
{
    public function __construct(
        private SentryEventScrubber $scrubber,
        private SentryLogScrubber $logScrubber,
    ) {
    }

    public function getBeforeSend(): callable
    {
        return function (?Event $event): ?Event {
            if (!$event instanceof Event) {
                return $event;
            }

            return $this->scrubber->scrubEvent($event);
        };
    }

    public function getBeforeSendTransaction(): callable
    {
        return function (?Event $event): ?Event {
            if (!$event instanceof Event) {
                return $event;
            }

            return $this->scrubber->scrubEvent($event);
        };
    }

    public function getBeforeBreadcrumb(): callable
    {
        return $this->scrubber->scrubBreadcrumb(...);
    }

    public function getBeforeSendLog(): callable
    {
        return $this->logScrubber->scrubLog(...);
    }
}
