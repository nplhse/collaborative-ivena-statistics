<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Sentry;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\UserDataBag;

final class SentryEventScrubber
{
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

    public function scrubEvent(Event $event): Event
    {
        $event->setExtra($this->scrubArray($event->getExtra()));
        $event->setTags($this->scrubTags($event->getTags()));
        $event->setRequest($this->scrubRequest($event->getRequest()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, $this->scrubArray($context));
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

        if (isset($request['headers']) && \is_array($request['headers'])) {
            $request['headers'] = $this->scrubArray($request['headers']);
        }

        return $request;
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
     *
     * @return array<array-key, mixed>
     */
    public function scrubArray(array $data): array
    {
        $scrubbed = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $scrubbed[$key] = '[Filtered]';

                continue;
            }

            if (\is_array($value)) {
                $scrubbed[$key] = $this->scrubArray($value);

                continue;
            }

            if (\is_string($value)) {
                $scrubbed[$key] = $this->truncate($value);

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

    private function truncate(string $value, int $maxLength = 256): string
    {
        if (\strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength).'…';
    }
}
