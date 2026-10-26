<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO\Messenger;

/**
 * Messenger counters and queue backlog over time for the page's charts (ADR 019): one point per hour, or per day
 * for the 30-day period. Counters without a row are 0; the backlog is null where no sample was taken.
 */
final readonly class MessengerHistoryDto
{
    /**
     * @param list<\DateTimeImmutable> $buckets
     * @param list<int>                $handled
     * @param list<int>                $failed
     * @param list<int>                $retried
     * @param list<int|null>           $averageDurationMs
     * @param list<int|null>           $backlog
     */
    public function __construct(
        public string $granularity,
        public array $buckets,
        public array $handled,
        public array $failed,
        public array $retried,
        public array $averageDurationMs,
        public array $backlog,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === array_sum($this->handled) + array_sum($this->failed) + array_sum($this->retried)
            && [] === array_filter($this->backlog, static fn (?int $value): bool => null !== $value);
    }

    /**
     * @return array{granularity: string, buckets: list<string>, handled: list<int>, failed: list<int>, retried: list<int>, duration: list<int|null>, backlog: list<int|null>}
     */
    public function toChartValue(): array
    {
        return [
            'granularity' => $this->granularity,
            'buckets' => array_map(static fn (\DateTimeImmutable $bucket): string => $bucket->format(\DATE_ATOM), $this->buckets),
            'handled' => $this->handled,
            'failed' => $this->failed,
            'retried' => $this->retried,
            'duration' => $this->averageDurationMs,
            'backlog' => $this->backlog,
        ];
    }
}
