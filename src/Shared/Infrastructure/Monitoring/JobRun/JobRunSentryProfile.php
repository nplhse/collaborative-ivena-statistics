<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\JobRun;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What one background job may send to Sentry. Each job's context provides its own profile;
 * SentryJobRunFilter applies the allowlist and SentryTracesSampler samples the ops completely.
 */
#[AutoconfigureTag(self::TAG)]
interface JobRunSentryProfile
{
    public const string TAG = 'app.job_run.sentry_profile';

    /** Name of the job's Sentry transaction. */
    public function transaction(): string;

    /**
     * Transaction ops that are always sampled.
     *
     * @return list<string>
     */
    public function sampledOps(): array;

    /** Spans of the transaction whose op does not start with this prefix (for example SQL query spans) are dropped. */
    public function spanOpPrefix(): string;

    /**
     * Log messages starting with one of these prefixes only keep allowlisted attributes.
     *
     * @return list<string>
     */
    public function logPrefixes(): array;

    /**
     * Keys that may leave the system in tags, span data, transaction data and log attributes.
     *
     * @return list<string>
     */
    public function allowedKeys(): array;
}
