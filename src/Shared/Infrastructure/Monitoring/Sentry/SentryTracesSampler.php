<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Sentry;

use App\Shared\Infrastructure\Monitoring\JobRun\JobRunSentryProfile;
use Sentry\Tracing\SamplingContext;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Samples rare, long background jobs completely (the ops of every JobRunSentryProfile),
 * never samples uptime checks and notification polling, and keeps the global rate for everything else.
 * Once a traces_sampler is set, Sentry ignores traces_sample_rate, so this sampler applies it itself.
 *
 * @psalm-suppress UnusedClass Registered as sentry.callback.traces_sampler.
 */
final readonly class SentryTracesSampler
{
    /**
     * Frequent requests without diagnostic value: uptime checks (`/health`) and notification polling (ADR 016).
     * Decided here, before the transaction collects spans; a sampled parent does not override it.
     *
     * @var list<string>
     */
    private const array UNSAMPLED_TRANSACTIONS = [
        'GET app_health',
        'GET app_notification_poll',
    ];

    /** @var list<string> */
    private array $fullySampledOps;

    /**
     * @param iterable<JobRunSentryProfile> $profiles
     */
    public function __construct(
        private float $tracesSampleRate,
        #[AutowireIterator(JobRunSentryProfile::TAG)]
        iterable $profiles = [],
    ) {
        $ops = [];
        foreach ($profiles as $profile) {
            $ops = [...$ops, ...$profile->sampledOps()];
        }
        $this->fullySampledOps = $ops;
    }

    public function __invoke(SamplingContext $context): float
    {
        if (\in_array($context->getTransactionContext()?->getName(), self::UNSAMPLED_TRANSACTIONS, true)) {
            return 0.0;
        }
        if (\in_array($context->getTransactionContext()?->getOp(), $this->fullySampledOps, true)) {
            return 1.0;
        }
        $parentSampled = $context->getParentSampled();
        if (null !== $parentSampled) {
            return $parentSampled ? 1.0 : 0.0;
        }

        return $this->tracesSampleRate;
    }
}
