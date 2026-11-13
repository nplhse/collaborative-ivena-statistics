<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Sentry;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Tracing\Span;
use Sentry\UserDataBag;

final class SentryEventScrubber
{
    /**
     * Trace and span data that may leave the system for transactions without their own allowlist
     * (job runs bring theirs, see SentryJobRunFilter). Unknown keys are dropped.
     *
     * @var list<string>
     */
    private const array ALLOWED_TRACE_DATA_KEYS = [
        'http.request.method',
        'http.response.status_code',
        'http.flavor',
        'http.url',
        'url.full',
        'route',
        'net.host.name',
        'net.host.port',
        'db.system',
        'cache.calls',
        'cache.hits',
        'cache.misses',
        'cache.writes',
        'cache.duration_ms',
    ];

    /** @var list<string> */
    private const array ALLOWED_TRACE_DATA_PREFIXES = [
        'sentry.',
        'thread.',
    ];

    /**
     * Keys whose string values are URLs (`url`, `http.url`, `url.full`, `request_uri`, `referer`):
     * query string and fragment are cut off, because filters, tokens or email addresses can sit in them.
     */
    private const string URL_KEY_PATTERN = '/(^|[._-])ur[il]([._-]|$)|^referer$/i';

    /** @var list<string> */
    private const array SENSITIVE_KEYS = [
        'password',
        'passwd',
        'token',
        'authorization',
        'cookie',
        'cookies',
        'secret',
        'api_key',
        'apikey',
        'email',
        'row',
        'rows',
        'payload',
        'csv',
        'file_path',
        'filepath',
        'path',
        'reason',
        'msg',
        'messages',
        'query_string',
        'body',
        'data',
    ];

    /**
     * @param bool $traceDataAllowlisted true when SentryJobRunFilter already reduced trace and span data to a job's allowlist
     */
    public function scrubEvent(Event $event, bool $traceDataAllowlisted = false): Event
    {
        $event->setExtra($this->scrubArray($event->getExtra()));
        $event->setTags($this->scrubTags($event->getTags()));
        $event->setRequest($this->scrubRequest($event->getRequest()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, 'trace' === $name
                ? $this->scrubTraceContext($context, $traceDataAllowlisted)
                : $this->scrubArray($context));
        }

        if (!$traceDataAllowlisted) {
            foreach ($event->getSpans() as $span) {
                $this->scrubSpan($span);
            }
        }

        $user = $event->getUser();
        if ($user instanceof UserDataBag) {
            $event->setUser(new UserDataBag(
                id: $user->getId(),
                email: null,
                ipAddress: null,
                username: $user->getUsername(),
            ));
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function scrubRequest(array $request): array
    {
        unset($request['data'], $request['cookies'], $request['query_string'], $request['body']);

        if (isset($request['url']) && \is_string($request['url'])) {
            $request['url'] = $this->stripQuery($request['url']);
        }

        if (isset($request['headers']) && \is_array($request['headers'])) {
            $request['headers'] = $this->scrubArray($request['headers']);
        }

        return $request;
    }

    /**
     * Keeps `data` an object: the key `data` would otherwise match as sensitive and replace it by a string.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function scrubTraceContext(array $context, bool $traceDataAllowlisted): array
    {
        $data = $context['data'] ?? null;
        unset($context['data']);
        $scrubbed = $this->scrubArray($context);

        if (\is_array($data)) {
            /** @var array<string, mixed> $traceData */
            $traceData = $data;
            $scrubbed['data'] = $traceDataAllowlisted ? $traceData : $this->filterTraceData($traceData);
        }

        return $scrubbed;
    }

    private function scrubSpan(Span $span): void
    {
        $description = $span->getDescription();
        if (null !== $description && str_starts_with((string) $span->getOp(), 'http.')) {
            $span->setDescription($this->stripQuery($description));
        }

        $data = $span->getData();
        $filtered = $this->filterTraceData($data);
        // Span::setData() merges, so dropped keys are overwritten.
        $dropped = array_fill_keys(array_keys(array_diff_key($data, $filtered)), '[Filtered]');
        $span->setData([...$filtered, ...$dropped]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function filterTraceData(array $data): array
    {
        $kept = [];
        foreach ($data as $key => $value) {
            $allowed = \in_array($key, self::ALLOWED_TRACE_DATA_KEYS, true)
                || array_any(self::ALLOWED_TRACE_DATA_PREFIXES, static fn (string $prefix): bool => str_starts_with($key, $prefix));
            if (!$allowed) {
                continue;
            }

            if (\is_string($value)) {
                $kept[$key] = $this->truncate($this->isUrlKey($key) ? $this->stripQuery($value) : $value);

                continue;
            }

            if (\is_int($value) || \is_float($value) || \is_bool($value) || null === $value) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    /**
     * @param array<string, string> $tags
     *
     * @return array<string, string>
     */
    private function scrubTags(array $tags): array
    {
        $scrubbed = [];
        foreach ($tags as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $scrubbed[$key] = '[Filtered]';

                continue;
            }

            $scrubbed[$key] = $this->truncate($value);
        }

        return $scrubbed;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param bool                    $urlValues true inside a URL key (for example the list of `referer` header values)
     *
     * @return array<array-key, mixed>
     */
    public function scrubArray(array $data, bool $urlValues = false): array
    {
        $scrubbed = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $scrubbed[$key] = '[Filtered]';

                continue;
            }

            $isUrl = $urlValues || $this->isUrlKey($key);

            if (\is_array($value)) {
                $scrubbed[$key] = $this->scrubArray($value, $isUrl);

                continue;
            }

            if (\is_string($value)) {
                $scrubbed[$key] = $this->truncate($isUrl ? $this->stripQuery($value) : $value);

                continue;
            }

            if (\is_int($value) || \is_float($value) || \is_bool($value) || null === $value) {
                $scrubbed[$key] = $value;
            }
        }

        return $scrubbed;
    }

    public function scrubBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        if (Breadcrumb::TYPE_HTTP === $breadcrumb->getType()) {
            return null;
        }

        $scrubbed = $this->scrubArray($breadcrumb->getMetadata());
        $next = $breadcrumb;
        foreach ($scrubbed as $name => $value) {
            $next = $next->withMetadata($name, $value);
        }

        return $next;
    }

    private function isSensitiveKey(string|int $key): bool
    {
        $normalized = strtolower((string) $key);

        return array_any(self::SENSITIVE_KEYS, fn (string $sensitiveKey): bool => str_contains($normalized, $sensitiveKey));
    }

    private function isUrlKey(string|int $key): bool
    {
        return 1 === preg_match(self::URL_KEY_PATTERN, (string) $key);
    }

    private function stripQuery(string $url): string
    {
        $end = strcspn($url, '?#');

        return substr($url, 0, $end);
    }

    private function truncate(string $value, int $maxLength = 256): string
    {
        if (\strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength).'…';
    }
}
