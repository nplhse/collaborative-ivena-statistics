<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital;

final readonly class OpenRouteServiceCallPacer
{
    private const int RETRY_AFTER_CAP_SECONDS = 60;

    public function pauseBetweenCalls(bool $delayBeforeNextFetch, int $delayMs): void
    {
        if (!$delayBeforeNextFetch || $delayMs <= 0) {
            return;
        }

        usleep($delayMs * 1000);
    }

    public function pauseForRetryAfter(?int $retryAfterSeconds, int $delayMs): void
    {
        if ($delayMs <= 0) {
            return;
        }

        $milliseconds = null !== $retryAfterSeconds
            ? min($retryAfterSeconds, self::RETRY_AFTER_CAP_SECONDS) * 1000
            : $delayMs;

        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }
}
