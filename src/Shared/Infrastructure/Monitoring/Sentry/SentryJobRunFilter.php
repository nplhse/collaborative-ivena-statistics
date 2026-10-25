<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Sentry;

use App\Shared\Infrastructure\Monitoring\JobRun\JobRunSentryProfile;
use Sentry\Event;
use Sentry\Tracing\Span;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Telemetry of background jobs may only carry ids, counts, durations and fixed labels.
 * Hospital or department names, closure periods, file names and error messages stay in the database and local logs.
 * Allowlist, not denylist: unknown keys are dropped, and strings must look like labels or ids.
 * Each job brings its own allowlist as a JobRunSentryProfile.
 */
final readonly class SentryJobRunFilter
{
    private const string LABEL_PATTERN = '/^[A-Za-z0-9_.,:\\\\-]{0,128}$/';

    /** @var list<JobRunSentryProfile> */
    private array $profiles;

    /**
     * @param iterable<JobRunSentryProfile> $profiles
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        #[AutowireIterator(JobRunSentryProfile::TAG)]
        iterable $profiles,
    ) {
        $list = [];
        foreach ($profiles as $profile) {
            $list[] = $profile;
        }
        $this->profiles = $list;
    }

    public function isJobRunLog(string $message): bool
    {
        return $this->profileForLog($message) instanceof JobRunSentryProfile;
    }

    /**
     * Keeps SDK attributes (sentry.*, server.address) and the values the job's profile allows.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public function filterLogAttributes(string $message, array $attributes): array
    {
        $profile = $this->profileForLog($message);
        if (!$profile instanceof JobRunSentryProfile) {
            return $attributes;
        }

        $kept = [];
        foreach ($attributes as $key => $value) {
            if (str_starts_with($key, 'sentry.') || 'server.address' === $key) {
                $kept[$key] = $value;

                continue;
            }
            $value = $this->allowedValue($profile, $key, $value);
            if (null !== $value) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    /**
     * Leaves other transactions as they are. For a job run it drops foreign spans (for example query spans with SQL), descriptions and non-allowlisted data.
     */
    public function filterTransaction(Event $event): Event
    {
        $profile = $this->profileForTransaction($event);
        if (!$profile instanceof JobRunSentryProfile) {
            return $event;
        }

        $spans = [];
        foreach ($event->getSpans() as $span) {
            if (!str_starts_with((string) $span->getOp(), $profile->spanOpPrefix())) {
                continue;
            }
            $span->setDescription(null);
            $this->filterSpanData($profile, $span);
            $spans[] = $span;
        }
        $event->setSpans($spans);

        $trace = $event->getContexts()['trace'] ?? null;
        if (\is_array($trace) && isset($trace['data']) && \is_array($trace['data'])) {
            /** @var array<string, mixed> $data */
            $data = $trace['data'];
            $trace['data'] = $this->filterData($profile, $data);
            $event->setContext('trace', $trace);
        }

        $tags = [];
        foreach ($event->getTags() as $key => $value) {
            $value = $this->allowedValue($profile, $key, $value);
            if (\is_string($value)) {
                $tags[$key] = $value;
            }
        }
        $event->setTags($tags);
        $event->setExtra([]);

        return $event;
    }

    private function profileForLog(string $message): ?JobRunSentryProfile
    {
        foreach ($this->profiles as $profile) {
            if (array_any($profile->logPrefixes(), static fn (string $prefix): bool => str_starts_with($message, $prefix))) {
                return $profile;
            }
        }

        return null;
    }

    private function profileForTransaction(Event $event): ?JobRunSentryProfile
    {
        $transaction = $event->getTransaction();

        return array_find($this->profiles, static fn (JobRunSentryProfile $profile): bool => $profile->transaction() === $transaction);
    }

    private function filterSpanData(JobRunSentryProfile $profile, Span $span): void
    {
        $data = $span->getData();
        $filtered = $this->filterData($profile, $data);
        // Span::setData() merges, so dropped keys are overwritten.
        $dropped = array_fill_keys(array_keys(array_diff_key($data, $filtered)), '[Filtered]');
        $span->setData([...$filtered, ...$dropped]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function filterData(JobRunSentryProfile $profile, array $data): array
    {
        $kept = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'sentry.') || str_starts_with($key, 'thread.')) {
                $kept[$key] = $value;

                continue;
            }
            $value = $this->allowedValue($profile, $key, $value);
            if (null !== $value) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    /**
     * @return int|float|bool|string|list<int>|null null when the value must not leave the system
     */
    private function allowedValue(JobRunSentryProfile $profile, string $key, mixed $value): int|float|bool|string|array|null
    {
        if (!\in_array($key, $profile->allowedKeys(), true)) {
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
