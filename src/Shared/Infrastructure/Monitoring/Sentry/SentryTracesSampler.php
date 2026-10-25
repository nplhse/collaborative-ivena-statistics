<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Sentry;

use Sentry\Tracing\SamplingContext;

/**
 * Samples rare, long background operations completely and keeps the global rate for everything else.
 * Once a traces_sampler is set, Sentry ignores traces_sample_rate, so this sampler applies it itself.
 *
 * @psalm-suppress UnusedClass Registered as sentry.callback.traces_sampler.
 */
final readonly class SentryTracesSampler
{
    /** Transaction ops that are always sampled. */
    public const array FULLY_SAMPLED_OPS = ['closure.rebuild'];

    public function __construct(private float $tracesSampleRate)
    {
    }

    public function __invoke(SamplingContext $context): float
    {
        if (\in_array($context->getTransactionContext()?->getOp(), self::FULLY_SAMPLED_OPS, true)) {
            return 1.0;
        }
        $parentSampled = $context->getParentSampled();
        if (null !== $parentSampled) {
            return $parentSampled ? 1.0 : 0.0;
        }

        return $this->tracesSampleRate;
    }
}
