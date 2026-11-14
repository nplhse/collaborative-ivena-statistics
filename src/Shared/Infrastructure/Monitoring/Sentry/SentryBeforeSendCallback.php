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
        private SentryJobRunFilter $jobRunFilter,
        private SentryCacheSpanSummary $cacheSpanSummary = new SentryCacheSpanSummary(),
        private SentryTraceUserPolicy $traceUserPolicy = new SentryTraceUserPolicy(),
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
            // Error reports keep the user id; a transaction only with the performance consent of this request.
            if (!$this->traceUserPolicy->isUserAllowed()) {
                $event->setUser(null);
            }

            $isJobRun = $this->jobRunFilter->isJobRunTransaction($event);
            $event = $isJobRun
                ? $this->jobRunFilter->filterTransaction($event)
                : $this->cacheSpanSummary->summarize($event);

            return $this->scrubber->scrubEvent($event, traceDataAllowlisted: $isJobRun);
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
