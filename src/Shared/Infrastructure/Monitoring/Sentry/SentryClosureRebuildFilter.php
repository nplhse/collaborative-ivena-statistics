<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Sentry;

use Sentry\Event;
use Sentry\Tracing\Span;

/**
 * Closure rebuild telemetry may only carry ids, counts, durations and fixed labels.
 * Hospital or department names, closure periods and error messages stay in the database and local logs.
 * Allowlist, not denylist: unknown keys are dropped, and strings must look like labels or ids.
 */
final readonly class SentryClosureRebuildFilter
{
    public const string TRANSACTION = 'closure_rebuild';
    public const string SPAN_OP_PREFIX = 'closure.rebuild';

    /** @var list<string> */
    private const array LOG_PREFIXES = ['closure_rebuild.', 'closure_volume.'];

    /** @var list<string> */
    private const array ALLOWED_KEYS = [
        'attempts',
        'duration_ms',
        'error_class',
        'events',
        'full',
        'hospital_id',
        'hospital_ids',
        'hours_written',
        'kind',
        'partitions',
        'pass',
        'passes',
        'peak_memory_bytes',
        'publish_ms',
        'queries',
        'request_ids',
        'requests',
        'retained_bytes',
        'run',
        'run_id',
        'runs',
        'scope',
        'status',
        'threshold_exceeded',
        'thresholds',
        'trigger',
    ];

    private const string LABEL_PATTERN = '/^[A-Za-z0-9_.,:\\\\-]{0,128}$/';

    public function isClosureLog(string $message): bool
    {
        return array_any(self::LOG_PREFIXES, static fn (string $prefix): bool => str_starts_with($message, $prefix));
    }

    /**
     * Keeps SDK attributes (sentry.*, server.address) and allowlisted values.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public function filterLogAttributes(array $attributes): array
    {
        $kept = [];
        foreach ($attributes as $key => $value) {
            if (str_starts_with($key, 'sentry.') || 'server.address' === $key) {
                $kept[$key] = $value;

                continue;
            }
            $value = $this->allowedValue($key, $value);
            if (null !== $value) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    public function isClosureTransaction(Event $event): bool
    {
        return self::TRANSACTION === $event->getTransaction();
    }

    /**
     * Drops foreign spans (for example query spans with SQL), descriptions and non-allowlisted data.
     */
    public function filterTransaction(Event $event): Event
    {
        $spans = [];
        foreach ($event->getSpans() as $span) {
            if (!str_starts_with((string) $span->getOp(), self::SPAN_OP_PREFIX)) {
                continue;
            }
            $span->setDescription(null);
            $this->filterSpanData($span);
            $spans[] = $span;
        }
        $event->setSpans($spans);

        $trace = $event->getContexts()['trace'] ?? null;
        if (\is_array($trace) && isset($trace['data']) && \is_array($trace['data'])) {
            /** @var array<string, mixed> $data */
            $data = $trace['data'];
            $trace['data'] = $this->filterData($data);
            $event->setContext('trace', $trace);
        }

        $tags = [];
        foreach ($event->getTags() as $key => $value) {
            $value = $this->allowedValue($key, $value);
            if (\is_string($value)) {
                $tags[$key] = $value;
            }
        }
        $event->setTags($tags);
        $event->setExtra([]);

        return $event;
    }

    private function filterSpanData(Span $span): void
    {
        $data = $span->getData();
        $filtered = $this->filterData($data);
        // Span::setData() merges, so dropped keys are overwritten.
        $dropped = array_fill_keys(array_keys(array_diff_key($data, $filtered)), '[Filtered]');
        $span->setData([...$filtered, ...$dropped]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function filterData(array $data): array
    {
        $kept = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'sentry.') || str_starts_with($key, 'thread.')) {
                $kept[$key] = $value;

                continue;
            }
            $value = $this->allowedValue($key, $value);
            if (null !== $value) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    /**
     * @return int|float|bool|string|list<int>|null null when the value must not leave the system
     */
    private function allowedValue(string $key, mixed $value): int|float|bool|string|array|null
    {
        if (!\in_array($key, self::ALLOWED_KEYS, true)) {
            return null;
        }
        if (\is_int($value) || \is_float($value) || \is_bool($value)) {
            return $value;
        }
        if (\is_string($value)) {
            return 1 === preg_match(self::LABEL_PATTERN, $value) ? $value : null;
        }
        if (\is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $item): bool => \is_int($item))) {
            return array_map(intval(...), $value);
        }

        return null;
    }
}
