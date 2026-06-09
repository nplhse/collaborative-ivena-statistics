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
    ];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private SentryEventScrubber $eventScrubber,
    ) {
    }

    public function scrubLog(Log $log): ?Log
    {
        if (!$this->shouldSendLog($log)) {
            return null;
        }

        $attributes = $log->attributes()->toSimpleArray();
        $scrubbed = $this->eventScrubber->scrubArray($attributes);

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

        if (str_starts_with($message, 'reject.')) {
            return false;
        }

        if (!str_starts_with($message, 'import.')) {
            return true;
        }

        return \in_array($message, self::IMPORT_ALLOWLIST, true);
    }
}
