<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Sentry;

use Sentry\Logs\Log;

final readonly class SentryLogScrubber
{
    /** @var list<string> */
    private const array IMPORT_ALLOWLIST = [
        'import.summary',
        'import.not_found',
        'import.failed',
        'import.failed.precondition',
        'import.abort.unexpected',
        'import.abort.flush_failed',
        'import.rejects.cleared',
        'import.reject_file.deleted',
        'import.source_file.deleted',
        'import.file.delete_failed',
        'import.run.summary',
        'import.run.threshold_exceeded',
        'import.run.retries_exhausted',
    ];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private SentryEventScrubber $eventScrubber,
        private SentryJobRunFilter $jobRunFilter,
    ) {
    }

    public function scrubLog(Log $log): ?Log
    {
        if (!$this->shouldSendLog($log)) {
            return null;
        }

        $log->setBody($this->eventScrubber->scrubMessage($log->getBody()));
        $attributes = $log->attributes()->toSimpleArray();
        $scrubbed = $this->jobRunFilter->isJobRunLog($log->getBody())
            ? $this->jobRunFilter->filterLogAttributes($log->getBody(), $attributes)
            : $this->eventScrubber->scrubArray($attributes);

        foreach (array_keys($attributes) as $key) {
            $log->attributes()->forget($key);
        }

        foreach ($scrubbed as $key => $value) {
            $log->setAttribute($key, $value);
        }

        return $log;
    }

    private function shouldSendLog(Log $log): bool
    {
        $message = $log->getBody();

        // One entry per rejected row, with its validation context (#1577): never sent.
        if (str_starts_with($message, 'reject.') || str_starts_with($message, 'closure.reject.')) {
            return false;
        }

        // Failed sign-ins stay out of Sentry (maintainer decision on #1577): the application watches them itself.
        if (str_starts_with($message, 'security.login.') || str_starts_with($message, 'security.login_link.')) {
            return false;
        }

        if (!str_starts_with($message, 'import.')) {
            return true;
        }

        return \in_array($message, self::IMPORT_ALLOWLIST, true);
    }
}
