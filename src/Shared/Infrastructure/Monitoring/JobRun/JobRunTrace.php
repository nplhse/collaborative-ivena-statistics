<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\JobRun;

use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

/**
 * Sentry transaction, span stack, duration and peak memory of one background job run.
 *
 * The transaction is kept off the Sentry scope so Doctrine query spans stay with the console or worker transaction.
 * Span data must only hold ids, counts and durations; SentryJobRunFilter drops everything else before sending.
 */
final class JobRunTrace
{
    private readonly float $startedAt;

    /** @var non-empty-list<Span> */
    private array $spans;

    private bool $finished = false;

    private function __construct(private readonly Transaction $transaction)
    {
        $this->startedAt = microtime(true);
        $this->spans = [$transaction];
    }

    /**
     * Starts the transaction and resets the peak memory, so the run reports its own peak.
     *
     * @param array<string, string>                    $tags
     * @param array<string, int|bool|string|list<int>> $data ids and counts only
     */
    public static function start(HubInterface $hub, string $name, string $op, array $tags = [], array $data = []): self
    {
        memory_reset_peak_usage();
        $context = TransactionContext::make()->setName($name)->setOp($op)->setOrigin('manual.job_run');
        $context->setTags($tags);
        $context->setData($data);

        return new self($hub->startTransaction($context));
    }

    /**
     * @param array<string, int|bool|list<int>> $data ids and counts only
     */
    public function startSpan(string $op, array $data = []): void
    {
        if ($this->finished) {
            return;
        }
        $parent = $this->spans[array_key_last($this->spans)];
        $this->spans[] = $parent->startChild(SpanContext::make()->setOp($op)->setData($data));
    }

    /**
     * Finishes the innermost open span; the transaction itself is only finished by finish().
     *
     * @param array<string, int|bool|list<int>> $data ids and counts only
     */
    public function finishSpan(array $data = []): void
    {
        if (\count($this->spans) <= 1) {
            return;
        }
        $span = array_pop($this->spans);
        $span->setData($data);
        $span->finish();
    }

    /**
     * Adds finished child spans to the innermost open span for work that is interleaved in a loop, such as reading,
     * mapping and persisting rows. Each span carries the summed time of its phase; the spans are laid out one after
     * another from the parent's start, so the waterfall shows the share of each phase, not when it happened.
     *
     * @param array<string, array{seconds: float, data: array<string, int|bool>}> $phases op => summed seconds and data
     */
    public function addPhaseSpans(array $phases): void
    {
        if ($this->finished) {
            return;
        }
        $parent = $this->spans[array_key_last($this->spans)];
        $at = $parent->getStartTimestamp();
        foreach ($phases as $op => $phase) {
            $seconds = max(0.0, $phase['seconds']);
            $span = $parent->startChild(
                SpanContext::make()->setOp($op)->setStartTimestamp($at)->setData([...$phase['data'], 'summed' => true]),
            );
            $at += $seconds;
            $span->finish($at);
        }
    }

    public function seconds(): float
    {
        return microtime(true) - $this->startedAt;
    }

    public function durationMs(): int
    {
        return (int) round($this->seconds() * 1000.0);
    }

    public function peakMemoryBytes(): int
    {
        return memory_get_peak_usage(true);
    }

    /**
     * Closes open spans and sends the transaction once.
     *
     * @param array<string, string>                    $tags
     * @param array<string, int|bool|string|list<int>> $data ids and counts only
     */
    public function finish(bool $failed, array $tags = [], array $data = []): void
    {
        if ($this->finished) {
            return;
        }
        while (\count($this->spans) > 1) {
            $this->finishSpan();
        }
        $this->finished = true;
        $this->transaction->setData($data);
        $this->transaction->setTags($tags);
        $this->transaction->setStatus($failed ? SpanStatus::internalError() : SpanStatus::ok());
        $this->transaction->finish();
    }
}
