<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Sentry;

use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

/**
 * Wraps one logical block of a request in a Sentry span, for questions the automatic spans cannot answer
 * ("what makes this analysis slow?"). One span per block, never per row or item.
 *
 * The span becomes the current span while the block runs, so Doctrine queries inside it appear as its children.
 * Without an active transaction (no DSN, not sampled, tests) the block just runs.
 *
 * Span data may only hold counts, fixed labels and numeric ids: SentryEventScrubber drops keys that are not
 * on its allowlist, so add new keys there.
 */
final readonly class SentrySpanTracer
{
    /**
     * @psalm-suppress PossiblyUnusedMethod Autowired.
     */
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(): T                                            $block
     * @param array<string, int|float|bool|string>                     $data       known before the block runs
     * @param (\Closure(T): array<string, int|float|bool|string>)|null $resultData derived from the block's result
     *
     * @return T
     */
    public function trace(string $op, string $description, \Closure $block, array $data = [], ?\Closure $resultData = null): mixed
    {
        $parent = $this->hub->getSpan();
        if (!$parent instanceof Span) {
            return $block();
        }

        $span = $parent->startChild(
            SpanContext::make()->setOp($op)->setOrigin('manual')->setDescription($description)->setData($data)
        );
        $this->hub->setSpan($span);

        try {
            $result = $block();
            if ($resultData instanceof \Closure) {
                $span->setData($resultData($result));
            }

            return $result;
        } finally {
            $span->finish();
            $this->hub->setSpan($parent);
        }
    }
}
