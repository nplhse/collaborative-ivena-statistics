<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Sentry;

use Sentry\Event;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanId;

/**
 * Replaces the cache spans of a transaction by one summary in its trace data.
 *
 * The SDK records one span per cache call (200–430 per request here, mostly PropertyInfo lookups). Single
 * calls say little, but their sum shows how much of a request the cache takes. The summary keeps that:
 * `cache.calls`, `cache.hits`, `cache.misses`, `cache.writes` and `cache.duration_ms`. The duration only
 * adds up outermost cache spans, so a cache call made while computing a missed value is not counted twice;
 * it does include the time spent computing missed values.
 */
final readonly class SentryCacheSpanSummary
{
    public const string OP_PREFIX = 'cache.';

    public function summarize(Event $event): Event
    {
        $spans = $event->getSpans();
        /** @var array<string, SpanId|null> $cacheSpanIds cache span id => its parent id */
        $cacheSpanIds = [];
        foreach ($spans as $span) {
            if ($this->isCacheSpan($span)) {
                $cacheSpanIds[(string) $span->getSpanId()] = $span->getParentSpanId();
            }
        }

        if ([] === $cacheSpanIds) {
            return $event;
        }

        $calls = 0;
        $hits = 0;
        $misses = 0;
        $writes = 0;
        $seconds = 0.0;
        $kept = [];

        foreach ($spans as $span) {
            if (!$this->isCacheSpan($span)) {
                $span->setParentSpanId($this->nonCacheAncestor($span->getParentSpanId(), $cacheSpanIds));
                $kept[] = $span;

                continue;
            }

            ++$calls;
            $hit = $span->getData()['cache.hit'] ?? null;
            if (true === $hit) {
                ++$hits;
            } elseif (false === $hit) {
                ++$misses;
            }
            if ('cache.put' === $span->getOp()) {
                ++$writes;
            }

            $parentId = $span->getParentSpanId();
            $nested = null !== $parentId && \array_key_exists((string) $parentId, $cacheSpanIds);
            $end = $span->getEndTimestamp();
            if (!$nested && null !== $end) {
                $seconds += max(0.0, $end - $span->getStartTimestamp());
            }
        }

        $event->setSpans($kept);

        $trace = $event->getContexts()['trace'] ?? [];
        /** @var array<string, mixed> $data */
        $data = isset($trace['data']) && \is_array($trace['data']) ? $trace['data'] : [];
        $trace['data'] = [
            ...$data,
            'cache.calls' => $calls,
            'cache.hits' => $hits,
            'cache.misses' => $misses,
            'cache.writes' => $writes,
            'cache.duration_ms' => round($seconds * 1000.0, 1),
        ];
        $event->setContext('trace', $trace);

        return $event;
    }

    /**
     * A span created while computing a missed cache value hangs below the dropped cache span; move it up to the
     * nearest ancestor that stays.
     *
     * @param array<string, SpanId|null> $cacheSpanIds
     */
    private function nonCacheAncestor(?SpanId $parentId, array $cacheSpanIds): ?SpanId
    {
        while ($parentId instanceof SpanId && \array_key_exists((string) $parentId, $cacheSpanIds)) {
            $parentId = $cacheSpanIds[(string) $parentId];
        }

        return $parentId;
    }

    private function isCacheSpan(Span $span): bool
    {
        return str_starts_with((string) $span->getOp(), self::OP_PREFIX);
    }
}
